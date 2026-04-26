<?php

declare(strict_types=1);

namespace CapstoneNMS\Keygen\Commands;

use CapstoneNMS\Keygen\Crypto\Signer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'mint', description: 'Mint a signed CapstoneNMS license envelope.')]
final class MintCommand extends Command
{
    /** Tier defaults — mirror source/config/capstone.php so an out-of-band mint matches what the runtime expects. */
    private const TIERS = [
        'solo'       => ['admins' => 1,  'editors' => 0,  'authors' => 0,  'agents' => 1,  'features' => [],          'force_powered' => true],
        'team'       => ['admins' => 2,  'editors' => 3,  'authors' => 10, 'agents' => 3,  'features' => [],          'force_powered' => false],
        'pro'        => ['admins' => 5,  'editors' => 10, 'authors' => 25, 'agents' => 10, 'features' => ['paywall'], 'force_powered' => false],
        'enterprise' => ['admins' => -1, 'editors' => -1, 'authors' => -1, 'agents' => -1, 'features' => ['paywall'], 'force_powered' => false],
    ];

    protected function configure(): void
    {
        $this
            ->addOption('tier', null, InputOption::VALUE_REQUIRED, 'solo | team | pro | enterprise', 'pro')
            ->addOption('kind', null, InputOption::VALUE_REQUIRED, 'production | development | trial', 'production')
            ->addOption('customer', null, InputOption::VALUE_REQUIRED, 'Display name for the License page', null)
            ->addOption('customer-email', null, InputOption::VALUE_REQUIRED, 'Customer billing email', null)
            ->addOption('domains', null, InputOption::VALUE_REQUIRED, 'Comma-separated list (literal + *.subdomain wildcards)', null)
            ->addOption('expires', null, InputOption::VALUE_REQUIRED, 'YYYY-MM-DD (or +Nd / +Nm / +Ny)', '+1y')
            ->addOption('admins', null, InputOption::VALUE_REQUIRED, 'Override tier admin cap')
            ->addOption('editors', null, InputOption::VALUE_REQUIRED, 'Override tier editor cap')
            ->addOption('authors', null, InputOption::VALUE_REQUIRED, 'Override tier author cap')
            ->addOption('agents', null, InputOption::VALUE_REQUIRED, 'Override tier agent cap')
            ->addOption('feature', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Add a feature flag (repeatable)', [])
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output basename (writes .dat + .txt)', './out/license')
            ->addOption('private-key', null, InputOption::VALUE_REQUIRED, 'Path to the Ed25519 private key', __DIR__ . '/../../keys/private.key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tier = strtolower((string) $input->getOption('tier'));
        if (! isset(self::TIERS[$tier])) {
            $output->writeln('<error>Unknown tier: ' . $tier . '. Choose from: ' . implode(', ', array_keys(self::TIERS)) . '</error>');
            return Command::FAILURE;
        }

        $kind = strtolower((string) $input->getOption('kind'));
        if (! in_array($kind, ['production', 'development', 'trial'], true)) {
            $output->writeln('<error>kind must be production, development, or trial</error>');
            return Command::FAILURE;
        }

        $customer = (string) $input->getOption('customer');
        if ($customer === '') {
            $output->writeln('<error>--customer is required (the company / display name on the License page)</error>');
            return Command::FAILURE;
        }

        $domainsRaw = (string) ($input->getOption('domains') ?? '');
        $domains = array_values(array_filter(array_map('trim', explode(',', $domainsRaw))));
        if (empty($domains)) {
            $output->writeln('<error>--domains is required (e.g. acme.example or *.acme.example)</error>');
            return Command::FAILURE;
        }
        foreach ($domains as $d) {
            if ($d === '*' || $d === '**') {
                $output->writeln('<error>Bare * wildcard is not allowed. Use *.example.com instead.</error>');
                return Command::FAILURE;
            }
        }

        $privPath = (string) $input->getOption('private-key');
        if (! is_file($privPath)) {
            $output->writeln('<error>Private key not found at ' . $privPath . '. Run `keygen bootstrap` first.</error>');
            return Command::FAILURE;
        }
        $secret = file_get_contents($privPath);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $output->writeln('<error>Private key looks malformed (expected ' . SODIUM_CRYPTO_SIGN_SECRETKEYBYTES . ' bytes).</error>');
            return Command::FAILURE;
        }

        $tierConfig = self::TIERS[$tier];
        $expiresAt = $this->parseExpiry((string) $input->getOption('expires'));
        if (! $expiresAt) {
            $output->writeln('<error>--expires must be YYYY-MM-DD or +Nd/+Nm/+Ny.</error>');
            return Command::FAILURE;
        }

        $features = $input->getOption('feature') ?: [];
        if (empty($features)) $features = $tierConfig['features'];

        $payload = [
            'v'                       => 1,
            'id'                      => 'lic_' . bin2hex(random_bytes(8)),
            'kind'                    => $kind,
            'tier'                    => $tier,
            'customer'                => $customer,
            'customer_email'          => (string) ($input->getOption('customer-email') ?? ''),
            'domains'                 => $domains,
            'limits' => [
                'admins'  => $this->capOverride($input, 'admins', $tierConfig['admins']),
                'editors' => $this->capOverride($input, 'editors', $tierConfig['editors']),
                'authors' => $this->capOverride($input, 'authors', $tierConfig['authors']),
                'agents'  => $this->capOverride($input, 'agents', $tierConfig['agents']),
            ],
            'features'                => array_values(array_unique(array_filter($features, 'is_string'))),
            'force_powered_by_footer' => $kind !== 'production' ? true : $tierConfig['force_powered'],
            'issued_at'               => gmdate('c'),
            'expires_at'              => $expiresAt,
        ];

        $envelope = Signer::signEnvelope($payload, $secret);

        // Verify our own output before handing it to the customer.
        $publicKey = sodium_crypto_sign_publickey_from_secretkey($secret);
        if (Signer::verifyEnvelope($envelope, $publicKey) === null) {
            $output->writeln('<error>Round-trip verification failed. Aborting — do not ship this license.</error>');
            return Command::FAILURE;
        }

        $base = (string) $input->getOption('output');
        $fs = new Filesystem();
        $fs->mkdir(dirname($base));
        $fs->dumpFile($base . '.dat', $envelope . "\n");
        $fs->dumpFile($base . '.txt', $envelope . "\n");

        $output->writeln('<info>License minted.</info>');
        $output->writeln('  ID:        ' . $payload['id']);
        $output->writeln('  Customer:  ' . $payload['customer']);
        $output->writeln('  Kind:      ' . $payload['kind']);
        $output->writeln('  Tier:      ' . $payload['tier']);
        $output->writeln('  Domains:   ' . implode(', ', $payload['domains']));
        $output->writeln('  Expires:   ' . $payload['expires_at']);
        $output->writeln('  Files:     ' . $base . '.dat  (file upload form)');
        $output->writeln('             ' . $base . '.txt  (paste-blob form)');
        return Command::SUCCESS;
    }

    private function capOverride(InputInterface $input, string $key, int $default): int
    {
        $val = $input->getOption($key);
        if ($val === null) return $default;
        if ($val === '-1' || strtolower((string) $val) === 'unlimited') return -1;
        if (! preg_match('/^-?\d+$/', (string) $val)) {
            throw new \InvalidArgumentException("--{$key} must be an integer or -1");
        }
        return (int) $val;
    }

    private function parseExpiry(string $val): ?string
    {
        $val = trim($val);
        if (preg_match('/^\+(\d+)([dmy])$/', $val, $m)) {
            $n = (int) $m[1];
            $unit = ['d' => 'days', 'm' => 'months', 'y' => 'years'][$m[2]];
            $dt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            return $dt->modify("+{$n} {$unit}")->format('c');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
            return (new \DateTimeImmutable($val.'T23:59:59Z'))->format('c');
        }
        return null;
    }
}
