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

#[AsCommand(name: 'bootstrap', description: 'Generate an Ed25519 product keypair. One-time setup.')]
final class BootstrapCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite an existing key pair')
            ->addOption('out', null, InputOption::VALUE_REQUIRED, 'Override output directory', __DIR__ . '/../../keys');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fs = new Filesystem();
        $dir = (string) $input->getOption('out');
        $privPath = $dir . '/private.key';
        $pubPath  = $dir . '/public.key';

        if (! $fs->exists($dir)) {
            $fs->mkdir($dir, 0700);
        }

        if (($fs->exists($privPath) || $fs->exists($pubPath)) && ! $input->getOption('force')) {
            $output->writeln('<error>Keypair already exists. Re-run with --force to overwrite (this invalidates every license you\'ve ever issued).</error>');
            return Command::FAILURE;
        }

        $pair = Signer::newKeypair();

        $fs->dumpFile($privPath, $pair['private']);
        $fs->chmod($privPath, 0600);
        $fs->dumpFile($pubPath, $pair['public']);

        $pubB64 = Signer::base64UrlEncode($pair['public']);

        $output->writeln('');
        $output->writeln('<info>Keypair generated.</info>');
        $output->writeln('  Private: ' . $privPath . '  (chmod 600 — never commit, never email)');
        $output->writeln('  Public:  ' . $pubPath);
        $output->writeln('');
        $output->writeln('<comment>Paste this into source/config/capstone.php as license_public_key_b64:</comment>');
        $output->writeln('');
        $output->writeln('  ' . $pubB64);
        $output->writeln('');
        $output->writeln('Or set CAPSTONE_LICENSE_PUBLIC_KEY in the build env.');

        return Command::SUCCESS;
    }
}
