<?php

namespace App\Http\Controllers;

use App\Services\Install\InstallerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class InstallController extends Controller
{
    public function __construct(private readonly InstallerService $installer) {}

    public function show(Request $request)
    {
        if ($this->installer->isInstalled()) abort(404);

        // First-run bootstrap. A fresh dist has no .env, so APP_KEY
        // is missing — sessions / CSRF / encrypted cookies all fail
        // without one. Copy .env.example → .env and mint an APP_KEY
        // before the wizard renders. The installer's apply() preserves
        // this value when the customer submits.
        $bootstrapped = $this->ensureEnvBootstrapped();
        if ($bootstrapped) {
            // The freshly-written APP_KEY isn't visible to the current
            // request's bootstrapped Encrypter — redirect to self so
            // the next request reads .env from scratch.
            return redirect('/install');
        }

        $preflight = $this->installer->preflight();
        $blockingFailures = array_values(array_filter($preflight, fn ($c) => ! $c['ok'] && $c['severity'] === 'fail'));

        return view('pages.install', [
            'preflight'        => $preflight,
            'blockingFailures' => $blockingFailures,
            'old'              => session('_install_old', []),
            'errors'           => session('_install_errors', []),
        ]);
    }

    private function ensureEnvBootstrapped(): bool
    {
        $envPath = base_path('.env');
        $examplePath = base_path('.env.example');
        $changed = false;

        if (! file_exists($envPath)) {
            if (! file_exists($examplePath)) return false;
            copy($examplePath, $envPath);
            $changed = true;
        }

        $contents = (string) file_get_contents($envPath);
        if (! preg_match('/^APP_KEY=base64:/m', $contents)) {
            $key = 'base64:'.base64_encode(random_bytes(32));
            $line = 'APP_KEY='.$key;
            if (preg_match('/^APP_KEY=.*$/m', $contents)) {
                $contents = preg_replace('/^APP_KEY=.*$/m', $line, $contents);
            } else {
                $contents = rtrim($contents, "\n")."\n".$line."\n";
            }
            file_put_contents($envPath, $contents);
            $changed = true;
        }

        return $changed;
    }

    public function perform(Request $request)
    {
        if ($this->installer->isInstalled()) abort(404);

        $rules = [
            'site_name'      => 'required|string|max:120',
            'site_url'       => 'required|url|max:255',
            'db_driver'      => ['required', Rule::in(['mysql', 'sqlite'])],
            'db_host'        => 'required_unless:db_driver,sqlite|string|max:120',
            'db_port'        => 'required_unless:db_driver,sqlite|integer|between:1,65535',
            'db_database'    => 'required|string|max:120',
            'db_username'    => 'required_unless:db_driver,sqlite|string|max:120',
            'db_password'    => 'nullable|string|max:255',
            'admin_name'     => 'required|string|max:120',
            'admin_email'    => 'required|email|max:200',
            'admin_password' => 'required|string|min:12',
            'license_paste'  => 'nullable|string|max:65536',
            'license_file'   => 'nullable|file|max:64', // 64 KB ceiling
        ];

        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return redirect('/install')
                ->withInput()
                ->with('_install_errors', array_values($validator->errors()->all()));
        }

        $input = $request->all();
        if ($request->hasFile('license_file')) {
            $input['license_file_contents'] = (string) file_get_contents($request->file('license_file')->getPathname());
        }

        $result = $this->installer->apply($input);
        if (! $result['ok']) {
            return redirect('/install')
                ->withInput()
                ->with('_install_errors', $result['errors']);
        }

        // Successful install: redirect to admin login. Don't render
        // any DB-dependent view in the same request because the cache
        // / view-cache rebinding hasn't happened yet — let the next
        // request fully reboot the framework against the new env.
        return redirect('/admin/login')
            ->with('flash_message', 'CapstoneNMS installed. Sign in with the admin account you just created.');
    }
}
