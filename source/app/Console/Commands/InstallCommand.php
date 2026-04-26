<?php

namespace App\Console\Commands;

use App\Services\Install\InstallerService;
use Illuminate\Console\Command;

/**
 * Air-gapped / non-browser install path. Same preflight + apply
 * pipeline the web installer uses — different I/O.
 *
 *   php artisan capstone:install --interactive
 *   php artisan capstone:install \
 *       --site-name="Acme News" --site-url=https://acme.example \
 *       --db-database=acme --db-username=acme --db-password=secret \
 *       --admin-name="Admin" --admin-email=admin@acme.example --admin-password=… \
 *       --license=/path/to/file.dat
 */
class InstallCommand extends Command
{
    protected $signature = 'capstone:install
                            {--interactive : Prompt for each value}
                            {--site-name= : Display name for the site}
                            {--site-url= : Public URL (incl. https://)}
                            {--db-driver=mysql : mysql or sqlite}
                            {--db-host=127.0.0.1}
                            {--db-port=3306}
                            {--db-database=}
                            {--db-username=}
                            {--db-password=}
                            {--admin-name=}
                            {--admin-email=}
                            {--admin-password=}
                            {--license= : Path to a .dat / .txt envelope}';

    protected $description = 'Run the CapstoneNMS installer from the CLI (preflight + DB + admin + license).';

    public function handle(InstallerService $installer): int
    {
        if ($installer->isInstalled()) {
            $this->error('Already installed. Delete storage/app/private/installer/installed.lock to re-run.');
            return self::FAILURE;
        }

        $this->info('Running preflight…');
        $blocked = false;
        foreach ($installer->preflight() as $c) {
            $tag = $c['ok'] ? '<info>✓</info>' : ($c['severity'] === 'warn' ? '<comment>!</comment>' : '<error>✕</error>');
            $this->line(sprintf('  %s %-40s %s', $tag, $c['label'], $c['value']));
            if (! $c['ok'] && $c['severity'] === 'fail') $blocked = true;
        }
        if ($blocked) {
            $this->error('Preflight failed. Resolve the failed requirements above and rerun.');
            return self::FAILURE;
        }

        $interactive = (bool) $this->option('interactive');
        $get = function (string $key, string $prompt, bool $secret = false, ?string $default = null) use ($interactive) {
            $val = $this->option($key);
            if ($val !== null && $val !== '') return (string) $val;
            if (! $interactive) return (string) ($default ?? '');
            return $secret ? $this->secret($prompt) : (string) $this->ask($prompt, $default);
        };

        $input = [
            'site_name'      => $get('site-name', 'Site name'),
            'site_url'       => $get('site-url', 'Site URL (https://…)'),
            'db_driver'      => $get('db-driver', 'DB driver (mysql/sqlite)', false, 'mysql'),
            'db_host'        => $get('db-host', 'DB host', false, '127.0.0.1'),
            'db_port'        => $get('db-port', 'DB port', false, '3306'),
            'db_database'    => $get('db-database', 'DB database name'),
            'db_username'    => $get('db-username', 'DB username'),
            'db_password'    => $get('db-password', 'DB password', true),
            'admin_name'     => $get('admin-name', 'Admin name'),
            'admin_email'    => $get('admin-email', 'Admin email'),
            'admin_password' => $get('admin-password', 'Admin password (min 12 chars)', true),
        ];

        $licensePath = $this->option('license');
        if ($licensePath && file_exists($licensePath)) {
            $input['license_paste'] = trim((string) file_get_contents($licensePath));
        }

        if ($input['site_name'] === '' || $input['site_url'] === '' || $input['db_database'] === '' ||
            $input['admin_name'] === '' || $input['admin_email'] === '' || $input['admin_password'] === '') {
            $this->error('Missing required values. Pass --interactive to be prompted, or provide all --* options.');
            return self::FAILURE;
        }
        if (strlen($input['admin_password']) < 12) {
            $this->error('Admin password must be at least 12 characters.');
            return self::FAILURE;
        }

        $this->info('Applying installation…');
        $result = $installer->apply($input);
        if (! $result['ok']) {
            foreach ($result['errors'] as $err) $this->error('  '.$err);
            return self::FAILURE;
        }

        $this->info('CapstoneNMS installed.');
        $this->line('Sign in at '.$input['site_url'].'/admin');
        return self::SUCCESS;
    }
}
