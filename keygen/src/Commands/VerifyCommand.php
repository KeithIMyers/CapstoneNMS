<?php

declare(strict_types=1);

namespace CapstoneNMS\Keygen\Commands;

use CapstoneNMS\Keygen\Crypto\Signer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'verify', description: 'Verify a license envelope (file path or paste-blob).')]
final class VerifyCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('envelope', InputArgument::REQUIRED, 'Path to a .dat / .txt file, OR a paste-blob (raw envelope)')
            ->addOption('public-key', null, InputOption::VALUE_REQUIRED, 'Path to the Ed25519 public key', __DIR__ . '/../../keys/public.key');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $arg = (string) $input->getArgument('envelope');
        $envelope = is_file($arg) ? trim((string) file_get_contents($arg)) : trim($arg);
        if ($envelope === '') {
            $output->writeln('<error>Empty envelope.</error>');
            return Command::FAILURE;
        }

        $pubPath = (string) $input->getOption('public-key');
        if (! is_file($pubPath)) {
            $output->writeln('<error>Public key not found at ' . $pubPath . '. Run `keygen bootstrap` first.</error>');
            return Command::FAILURE;
        }
        $pub = file_get_contents($pubPath);
        if ($pub === false || strlen($pub) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            $output->writeln('<error>Public key looks malformed.</error>');
            return Command::FAILURE;
        }

        $payload = Signer::verifyEnvelope($envelope, $pub);
        if ($payload === null) {
            $output->writeln('<error>INVALID — signature did not verify.</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>VALID</info>');
        $output->writeln('');
        $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Friendly summary.
        $output->writeln('');
        $expires = isset($payload['expires_at']) ? new \DateTimeImmutable((string) $payload['expires_at']) : null;
        if ($expires) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $diff = (int) $expires->diff($now)->format('%R%a');
            if ($diff < 0) $output->writeln('  Expires in ' . abs($diff) . ' day(s)');
            else           $output->writeln('  <comment>Expired ' . $diff . ' day(s) ago</comment>');
        }
        return Command::SUCCESS;
    }
}
