<?php

namespace App\Http\Controllers;

use App\Services\Install\InstallerService;
use App\Services\Install\LayoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class InstallController extends Controller
{
    public function __construct(
        private readonly InstallerService $installer,
        private readonly LayoutService $layout,
    ) {}

    public function show(Request $request)
    {
        if ($this->installer->isInstalled()) abort(404);

        // pre_layout: customer extracted the dist into a shared-host
        // doc root (public_html / public / www / htdocs). Offer the
        // one-click relayout before the wizard so the rest of the
        // install pipeline runs against the canonical SHARED layout.
        if ($this->layout->detect() === 'pre_layout') {
            return view('pages.install-relayout');
        }

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
            // Renamed from 'errors' because Laravel's
            // ShareErrorsFromSession middleware injects $errors as a
            // ViewErrorBag — our session flash array would be
            // shadowed and the view would never show install errors.
            'installErrors'    => session('_install_errors', []),
        ]);
    }

    /**
     * One-click relayout from PRE_LAYOUT into SHARED. After the
     * filesystem moves complete, the same /install URL renders the
     * normal wizard (because base_path() now resolves to <docroot>/_app/
     * and the layout marker reads "shared").
     */
    public function relayout(Request $request)
    {
        if ($this->installer->isInstalled()) abort(404);
        if ($this->layout->detect() !== 'pre_layout') {
            // Already in standard or shared — nothing to do.
            return redirect('/install');
        }

        $result = $this->layout->relayoutToShared();

        if (! $result['ok']) {
            return view('pages.install-relayout', [
                'errors' => $result['errors'],
                'moved'  => $result['moved'],
            ]);
        }

        // Mark the install lock so we know the relayout was clean,
        // then redirect to the wizard. The relayout invalidates the
        // current Laravel boot (paths changed), but the layout-aware
        // public/index.php picks up the new location on the next
        // request.
        return redirect('/install')
            ->with('flash_message', "Relayout complete — {$result['moved']} top-level entries moved into _app/.");
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
