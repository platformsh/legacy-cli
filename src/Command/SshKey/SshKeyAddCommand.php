<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\SshKey;

use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\SshConfig;
use Platformsh\Cli\Service\SshKey;
use Platformsh\Cli\Service\QuestionHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ssh-key:add', description: 'Add a new SSH key')]
class SshKeyAddCommand extends SshKeyCommandBase
{
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Io $io, private readonly QuestionHelper $questionHelper, private readonly Shell $shell, private readonly SshConfig $sshConfig, private readonly SshKey $sshKey)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'The path to an existing SSH public key')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'A name to identify the key');

        $help = 'This command lets you add an SSH key to your account. It can generate a key using OpenSSH.'
            . "\n\n" . $this->certificateNotice($this->config);
        $this->setHelp($help);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sshDir = $this->config->getHomeDirectory() . DIRECTORY_SEPARATOR . '.ssh';

        $this->stdErr->writeln(sprintf(
            "Adding an SSH key to your %s account (<info>%s</info>)\n",
            $this->config->getStr('service.name'),
            $this->api->getMyAccount()['email'],
        ));

        $this->stdErr->writeln($this->certificateNotice($this->config, false));
        $this->stdErr->writeln('');
        if (!$this->questionHelper->confirm('Are you sure you want to continue adding a key?', false)) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(\sprintf('To load or check your SSH certificate, run: <info>%s ssh-cert:load</info>', $this->config->getStr('application.executable')));
            return 1;
        }
        $this->stdErr->writeln('');

        $publicKeyPath = $input->getArgument('path');
        if (empty($publicKeyPath)) {
            $defaultKeyPath = $sshDir . DIRECTORY_SEPARATOR . 'id_ed25519';
            $defaultPublicKeyPath = $defaultKeyPath . '.pub';

            // Look for an existing local key.
            if (\file_exists($defaultPublicKeyPath)
                && $this->questionHelper->confirm(
                    'Use existing local key <info>' . \basename($defaultPublicKeyPath) . '</info>?',
                )) {
                $this->stdErr->writeln('');
                $publicKeyPath = $defaultPublicKeyPath;
            } elseif ($this->shell->commandExists('ssh-keygen')
                && $this->questionHelper->confirm('Generate a new key?')) {
                // Offer to generate a key.
                $newKeyPath = $this->askNewKeyPath();
                $this->stdErr->writeln('');

                $args = ['ssh-keygen', '-t', 'ed25519', '-f', $newKeyPath, '-N', ''];
                $this->shell->mustExecute($args);
                $publicKeyPath = $newKeyPath . '.pub';
                $this->stdErr->writeln("Generated a new key: $publicKeyPath\n");

                // An SSH agent is required if the key's filename is not an OpenSSH default.
                if (!in_array(basename($newKeyPath), $this->sshKey->defaultKeyNames())) {
                    $this->stdErr->writeln('Add this key to an SSH agent with:');
                    $this->stdErr->writeln('    eval $(ssh-agent)');
                    $this->stdErr->writeln('    ssh-add ' . \escapeshellarg($newKeyPath));
                    $this->stdErr->writeln('');
                }
            } else {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('You must specify the path to a public SSH key');
                return 1;
            }
        } elseif (!str_contains((string) $publicKeyPath, '.pub') && \file_exists($publicKeyPath . '.pub')) {
            $publicKeyPath .= '.pub';
            $this->io->debug('Using public key: ' . $publicKeyPath . '.pub');
        }

        if (!\file_exists($publicKeyPath)) {
            $this->stdErr->writeln("File not found: <error>$publicKeyPath<error>");
            return 1;
        }

        // Use ssh-keygen to help validate the key.
        if ($this->shell->commandExists('ssh-keygen')) {
            if ($this->shell->execute(['ssh-keygen', '-l', '-f', $publicKeyPath]) === false) {
                $this->stdErr->writeln("The file does not contain a valid public key: <error>$publicKeyPath</error>");
                return 1;
            }
        }

        $fingerprint = $this->sshKey->getPublicKeyFingerprint($publicKeyPath);

        // Check whether the public key already exists in the user's account.
        if ($this->keyExistsByFingerprint($fingerprint)) {
            $this->stdErr->writeln('This key already exists in your account.');
            $this->stdErr->writeln(\sprintf(
                'List your SSH keys with: <info>%s ssh-keys</info>',
                $this->config->getStr('application.executable'),
            ));

            return 0;
        }

        // Get the public key content.
        $publicKey = \file_get_contents($publicKeyPath);
        if ($publicKey === false) {
            $this->stdErr->writeln("Failed to read public key file: <error>$publicKeyPath</error>");
            return 1;
        }

        // Add the new key.
        $this->api->getClient()->addSshKey($publicKey, $input->getOption('name'));

        $this->stdErr->writeln(\sprintf(
            'The SSH key <info>%s</info> has been successfully added to your %s account.',
            \basename((string) $publicKeyPath),
            $this->config->getStr('service.name'),
        ));

        // Reset and warm the SSH keys cache.
        try {
            $this->api->getSshKeys(true);
        } catch (\Exception) {
            // Suppress exceptions; we do not need the result of this call.
        }
        if ($this->sshConfig->configureSessionSsh()) {
            $this->sshConfig->addUserSshConfig($this->questionHelper);
        }

        return 0;
    }

    /**
     * Check whether the SSH key already exists in the user's account.
     *
     * @param string $fingerprint The public key fingerprint (as an MD5 hash).
     *
     * @return bool
     */
    protected function keyExistsByFingerprint(string $fingerprint): bool
    {
        foreach ($this->api->getClient()->getSshKeys() as $existingKey) {
            if ($existingKey->fingerprint === $fingerprint) {
                return true;
            }
        }

        return false;
    }

    /**
     * Finds the default path for a new SSH key.
     */
    private function askNewKeyPath(): string
    {
        $basename = 'id_ed25519-' . $this->config->getStr('application.slug') . '-' . $this->api->getMyAccount()['username'];
        $sshDir = $this->config->getHomeDirectory() . DIRECTORY_SEPARATOR . '.ssh';
        for ($i = 2; \file_exists($sshDir . DIRECTORY_SEPARATOR . $basename); $i++) {
            $basename .= $i;
        }

        return $this->questionHelper->askInput('Enter a filename for the new key (relative to ~/.ssh)', $basename, [], function ($path) use ($sshDir) {
            if (!str_starts_with($path, '/')) {
                if (\substr($path, 0, 1) === '~/') {
                    $path = $this->config->getHomeDirectory() . '/' . \substr($path, 2);
                } else {
                    $path = $sshDir . DIRECTORY_SEPARATOR . ltrim($path, '\\/');
                }
            }
            if (\file_exists($path)) {
                throw new \RuntimeException('The file already exists: ' . $path);
            }
            return $path;
        });
    }
}
