<?php

namespace App\Services\Install;

use App\Services\Licensing\LicenseService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Drives the web installer.
 *
 *   isInstalled()                  presence of storage/app/private/installer/installed.lock
 *   preflight()                    array of pass/fail checks the wizard renders
 *   testDbConnection($creds)       PDO connect attempt; returns null on ok, error string on fail
 *   apply($input)                  one-shot: writes .env, migrates, creates admin,
 *                                  saves license, marks installed; returns array
 *                                  of {ok, errors[]} so the controller can show
 *                                  inline failures on the wizard.
 *
 * Everything in here is single-shot and must be safe to re-run on
 * partial failure. The lock file is the LAST thing apply() writes,
 * so a botched apply leaves the customer back on the install page
 * able to retry.
 */
class InstallerService
{
    public const LOCK_PATH = 'installer/installed.lock';

    /** PHP extensions that must be loaded for the app to work at all. */
    private const REQUIRED_EXTENSIONS = [
        'bcmath', 'ctype', 'curl', 'dom', 'fileinfo',
        'intl', 'mbstring', 'openssl', 'pdo', 'tokenizer',
        'xml', 'zip', 'sodium',
    ];

    /** PHP extensions where ANY of the listed satisfies the requirement. */
    private const ANY_OF_EXTENSIONS = [
        'image' => ['gd', 'imagick'],
        'pdo_driver' => ['pdo_mysql', 'pdo_sqlite'],
    ];

    public function isInstalled(): bool
    {
        try {
            return Storage::disk('local')->exists(self::LOCK_PATH);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array<int, array{key:string, label:string, ok:bool, value:string, severity:string}>
     */
    public function preflight(): array
    {
        $checks = [];

        // PHP version — hard requirement from composer.json.
        $php = PHP_VERSION;
        $phpOk = version_compare($php, '8.3.0', '>=');
        $checks[] = $this->check('php', "PHP version (≥ 8.3 required)", $phpOk, $php);

        // Discrete extensions.
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $checks[] = $this->check("ext_{$ext}", "PHP extension: {$ext}", extension_loaded($ext), extension_loaded($ext) ? 'loaded' : 'missing');
        }

        // Either-of bundles.
        foreach (self::ANY_OF_EXTENSIONS as $key => $alts) {
            $loaded = array_filter($alts, 'extension_loaded');
            $ok = ! empty($loaded);
            $label = match ($key) {
                'image' => 'Image extension (gd or imagick)',
                'pdo_driver' => 'PDO driver (pdo_mysql or pdo_sqlite)',
                default => $key,
            };
            $checks[] = $this->check("any_{$key}", $label, $ok, $ok ? implode(', ', $loaded) : 'none of: '.implode(', ', $alts));
        }

        // Filesystem write permissions.
        $writePaths = [
            'storage/'           => storage_path(),
            'storage/framework/' => storage_path('framework'),
            'storage/logs/'      => storage_path('logs'),
            'storage/app/'       => storage_path('app'),
            'bootstrap/cache/'   => base_path('bootstrap/cache'),
        ];
        foreach ($writePaths as $label => $path) {
            $writable = is_dir($path) && is_writable($path);
            $checks[] = $this->check("write_{$label}", "Writable: {$label}", $writable, $writable ? 'OK' : 'not writable');
        }

        // .env writability — the installer needs to write APP_KEY + DB creds.
        $envPath = base_path('.env');
        $envWritable = file_exists($envPath) ? is_writable($envPath) : is_writable(base_path());
        $checks[] = $this->check('env_writable', 'Can write .env', $envWritable, $envWritable ? 'OK' : 'cannot write '.$envPath);

        // memory_limit recommendation.
        $mem = ini_get('memory_limit');
        $memBytes = $this->parseSize((string) $mem);
        $memOk = $memBytes < 0 || $memBytes >= 256 * 1024 * 1024;
        $checks[] = $this->check('mem', 'memory_limit (≥ 256M recommended)', $memOk, $mem, $memOk ? 'pass' : 'warn');

        // Free disk on the storage path (warn under 200MB).
        $free = @disk_free_space(storage_path());
        $diskOk = $free === false || $free >= 200 * 1024 * 1024;
        $checks[] = $this->check('disk', 'Free disk space (≥ 200 MB recommended)', $diskOk, $free === false ? 'unknown' : $this->humanBytes((int) $free), $diskOk ? 'pass' : 'warn');

        return $checks;
    }

    /**
     * @return string|null Error message, or null on success.
     */
    public function testDbConnection(array $creds): ?string
    {
        $driver   = $creds['driver']   ?? 'mysql';
        $host     = $creds['host']     ?? '127.0.0.1';
        $port     = (int) ($creds['port'] ?? 3306);
        $database = $creds['database'] ?? '';
        $username = $creds['username'] ?? '';
        $password = $creds['password'] ?? '';

        try {
            if ($driver === 'sqlite') {
                $path = $database ?: database_path('database.sqlite');
                if (! file_exists($path)) {
                    if (! @touch($path)) return "Cannot create SQLite file at {$path}";
                }
                new \PDO("sqlite:{$path}");
                return null;
            }

            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
            new \PDO($dsn, $username, $password, [
                \PDO::ATTR_TIMEOUT => 5,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * @return array{ok:bool, errors:array<int,string>}
     */
    public function apply(array $input): array
    {
        $errors = [];

        // 1. Final preflight gate.
        foreach ($this->preflight() as $c) {
            if (! $c['ok'] && $c['severity'] === 'fail') {
                $errors[] = "Server requirement failed: {$c['label']} ({$c['value']})";
            }
        }

        // 2. DB connection test.
        $dbErr = $this->testDbConnection([
            'driver'   => $input['db_driver']   ?? 'mysql',
            'host'     => $input['db_host']     ?? '127.0.0.1',
            'port'     => $input['db_port']     ?? 3306,
            'database' => $input['db_database'] ?? '',
            'username' => $input['db_username'] ?? '',
            'password' => $input['db_password'] ?? '',
        ]);
        if ($dbErr !== null) {
            $errors[] = "Database connection failed: {$dbErr}";
        }

        // Bail before mutating anything if preflight or DB failed.
        if (! empty($errors)) {
            return ['ok' => false, 'errors' => $errors];
        }

        // 3. Write .env. We start from .env.example so all the
        //    variables the runtime expects are present, then layer
        //    the customer's values on top.
        try {
            $this->writeEnv($input);
        } catch (\Throwable $e) {
            return ['ok' => false, 'errors' => ['Could not write .env: '.$e->getMessage()]];
        }

        // 4. Reconnect using the freshly-written DB credentials so
        //    Artisan::migrate runs against the customer's database.
        $this->rebindDb($input);

        // 5. Run migrations.
        try {
            Artisan::call('migrate', ['--force' => true]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'errors' => ['Migrations failed: '.$e->getMessage()]];
        }

        // 6. Create the first admin.
        try {
            \App\Models\User::forceCreate([
                'name'     => (string) $input['admin_name'],
                'email'    => (string) $input['admin_email'],
                'password' => Hash::make((string) $input['admin_password']),
                'role'     => 'admin',
                'status'   => 1,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'errors' => ['Admin creation failed: '.$e->getMessage()]];
        }

        // 7. Save the license envelope (file upload OR pasted blob).
        $envelope = trim((string) ($input['license_paste'] ?? ''));
        if ($envelope === '' && ! empty($input['license_file_contents'])) {
            $envelope = trim((string) $input['license_file_contents']);
        }
        if ($envelope !== '') {
            try {
                Storage::disk('local')->put(LicenseService::STORAGE_PATH, $envelope);
                app(LicenseService::class)->flush();
            } catch (\Throwable $e) {
                return ['ok' => false, 'errors' => ['License save failed: '.$e->getMessage()]];
            }
        }

        // 8. Mark installed last — if anything above blew up, the
        //    customer can retry without a stale lock blocking them.
        try {
            Storage::disk('local')->put(self::LOCK_PATH, json_encode([
                'installed_at' => gmdate('c'),
                'version'      => (string) config('capstone.product_version'),
                'license_id'   => (string) (app(LicenseService::class)->status()['license_id'] ?? ''),
            ], JSON_PRETTY_PRINT));
        } catch (\Throwable $e) {
            return ['ok' => false, 'errors' => ['Could not write install lock: '.$e->getMessage()]];
        }

        return ['ok' => true, 'errors' => []];
    }

    private function writeEnv(array $input): void
    {
        $base = base_path('.env.example');
        $target = base_path('.env');

        $contents = file_exists($base) ? (string) file_get_contents($base) : '';
        if ($contents === '') {
            $contents = "APP_NAME=\nAPP_ENV=production\nAPP_KEY=\nAPP_DEBUG=false\nAPP_URL=\n";
        }

        $contents = $this->replaceEnv($contents, 'APP_NAME', $this->envValue((string) ($input['site_name'] ?? 'CapstoneNMS')));
        $contents = $this->replaceEnv($contents, 'APP_ENV', 'production');
        $contents = $this->replaceEnv($contents, 'APP_DEBUG', 'false');
        $contents = $this->replaceEnv($contents, 'APP_URL', (string) ($input['site_url'] ?? ''));

        $contents = $this->replaceEnv($contents, 'DB_CONNECTION', (string) ($input['db_driver'] ?? 'mysql'));
        $contents = $this->replaceEnv($contents, 'DB_HOST',     (string) ($input['db_host'] ?? '127.0.0.1'));
        $contents = $this->replaceEnv($contents, 'DB_PORT',     (string) ($input['db_port'] ?? 3306));
        $contents = $this->replaceEnv($contents, 'DB_DATABASE', (string) ($input['db_database'] ?? ''));
        $contents = $this->replaceEnv($contents, 'DB_USERNAME', (string) ($input['db_username'] ?? ''));
        $contents = $this->replaceEnv($contents, 'DB_PASSWORD', $this->envValue((string) ($input['db_password'] ?? '')));

        $contents = $this->replaceEnv($contents, 'SESSION_DRIVER', 'file');
        $contents = $this->replaceEnv($contents, 'CACHE_STORE', 'file');

        // Generate APP_KEY if absent. base64:... is what `key:generate` produces.
        if (! preg_match('/^APP_KEY=base64:/m', $contents)) {
            $key = 'base64:'.base64_encode(random_bytes(32));
            $contents = $this->replaceEnv($contents, 'APP_KEY', $key);
        }

        file_put_contents($target, $contents);
    }

    private function rebindDb(array $input): void
    {
        $driver = $input['db_driver'] ?? 'mysql';
        config([
            'database.default' => $driver,
            "database.connections.{$driver}.host"     => $input['db_host'] ?? '127.0.0.1',
            "database.connections.{$driver}.port"     => $input['db_port'] ?? 3306,
            "database.connections.{$driver}.database" => $input['db_database'] ?? '',
            "database.connections.{$driver}.username" => $input['db_username'] ?? '',
            "database.connections.{$driver}.password" => $input['db_password'] ?? '',
        ]);
        DB::purge();
        DB::reconnect();
    }

    private function replaceEnv(string $contents, string $key, string $value): string
    {
        $line = $key.'='.$value;
        if (preg_match("/^{$key}=.*$/m", $contents)) {
            return preg_replace("/^{$key}=.*$/m", $line, $contents);
        }
        return rtrim($contents, "\n")."\n".$line."\n";
    }

    private function envValue(string $v): string
    {
        // Quote if the value contains spaces / # / ". Otherwise emit raw.
        if ($v === '' || preg_match('/[\s#"]/', $v)) {
            return '"'.str_replace('"', '\\"', $v).'"';
        }
        return $v;
    }

    private function check(string $key, string $label, bool $ok, string $value, string $severity = 'fail'): array
    {
        return [
            'key'     => $key,
            'label'   => $label,
            'ok'      => $ok,
            'value'   => $value,
            'severity'=> $ok ? 'pass' : $severity, // 'pass' | 'warn' | 'fail'
        ];
    }

    private function parseSize(string $val): int
    {
        $val = trim($val);
        if ($val === '' || $val === '-1') return -1;
        $unit = strtolower(substr($val, -1));
        $n = (int) $val;
        return match ($unit) {
            'g' => $n * 1024 * 1024 * 1024,
            'm' => $n * 1024 * 1024,
            'k' => $n * 1024,
            default => (int) $val,
        };
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $b = (float) $bytes;
        while ($b >= 1024 && $i < count($units) - 1) {
            $b /= 1024;
            $i++;
        }
        return number_format($b, 1).' '.$units[$i];
    }
}
