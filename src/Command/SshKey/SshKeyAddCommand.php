<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\SshKey;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\SshConfig;
use Platformsh\Cli\Service\SshKey;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SshKeyAddCommand extends CommandBase
{
    const DEFAULT_BASENAME = 'id_rsa';

    protected static $defaultName = 'ssh-key:add';

    private $api;
    private $config;
    private $questionHelper;
    private $shell;
    private $sshConfig;
    private $sshKey;

    public function __construct(
        Api $api,
        Config $config,
        QuestionHelper $questionHelper,
        Shell $shell,
        SshConfig $sshConfig,
        SshKey $sshKey
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->questionHelper = $questionHelper;
        $this->shell = $shell;
        $this->sshConfig = $sshConfig;
        $this->sshKey = $sshKey;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Add a new SSH key')
            ->addArgument('path', InputArgument::OPTIONAL, 'The path to an existing SSH public key')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'A name to identify the key');
        $this->addExample('Add an existing public key', '~/.ssh/id_rsa.pub');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $sshDir = $this->config->getHomeDirectory() . DIRECTORY_SEPARATOR . '.ssh';

        if ($this->api->authApiEnabled()) {
            $email = $this->api->getUser()->email;
        } else {
            $email = $this->api->getMyAccount()['mail'];
        }
        $this->stdErr->writeln(sprintf(
            "Adding an SSH key to your %s account (<info>%s</info>)\n",
            $this->config->get('service.name'),
            $email
        ));

        $publicKeyPath = $input->getArgument('path');
        if (empty($publicKeyPath)) {
            $defaultKeyPath = $sshDir . DIRECTORY_SEPARATOR . 'id_rsa';
            $defaultPublicKeyPath = $defaultKeyPath . '.pub';

            // Look for an existing local key.
            if (\file_exists($defaultPublicKeyPath)
                && $this->questionHelper->confirm(
                    'Use existing local key <info>' . \basename($defaultPublicKeyPath) . '</info>?'
                )) {
                $this->stdErr->writeln('');
                $publicKeyPath = $defaultPublicKeyPath;
            } elseif ($this->shell->commandExists('ssh-keygen')
                && $this->questionHelper->confirm('Generate a new key?')) {
                // Offer to generate a key.
                $newKeyPath = $this->askNewKeyPath($this->questionHelper);
                $this->stdErr->writeln('');

                $args = ['ssh-keygen', '-t', 'rsa', '-f', $newKeyPath, '-N', ''];
                $this->shell->execute($args, null, true);
                $publicKeyPath = $newKeyPath . '.pub';
                $this->stdErr->writeln("Generated a new key: $publicKeyPath\n");

                // An SSH agent is required if the key's filename is unusual.
                if (!in_array(basename($newKeyPath), ['id_rsa', 'id_dsa'])) {
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
        } elseif (\strpos($publicKeyPath, '.pub') === false && \file_exists($publicKeyPath . '.pub')) {
            $publicKeyPath .= '.pub';
            $this->debug('Using public key: ' . $publicKeyPath . '.pub');
        }

        if (!\file_exists($publicKeyPath)) {
            $this->stdErr->writeln("File not found: <error>$publicKeyPath<error>");
            return 1;
        }

        // Use ssh-keygen to help validate the key.
        if ($this->shell->commandExists('ssh-keygen')) {
            $args = ['ssh-keygen', '-l', '-f', $publicKeyPath];
            if (!$this->shell->execute($args)) {
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
                $this->config->get('application.executable')
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
            \basename($publicKeyPath),
            $this->config->get('service.name')
        ));

        // Reset and warm the SSH keys cache.
        try {
            $this->api->getSshKeys(true);
        } catch (\Exception $e) {
            // Suppress exceptions; we do not need the result of this call.
        }

        if ($this->sshConfig->configureSessionSsh()) {
            $this->sshConfig->addUserSshConfig();
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
    protected function keyExistsByFingerprint($fingerprint)
    {
        foreach ($this->api->getClient()->getSshKeys() as $existingKey) {
            if ($existingKey->fingerprint === $fingerprint) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the default path for a new SSH key.
     *
     * @param QuestionHelper $questionHelper
     *
     * @return string
     */
    private function askNewKeyPath(QuestionHelper $questionHelper)
    {
        $basename = 'id_rsa-' . $this->config->get('service.slug');
        if ($this->api->authApiEnabled()) {
            $username = $this->api->getUser()->username;
        } else {
            $username = $this->api->getMyAccount()['username'];
        }
        $basename .= '-' . $username;
        $sshDir = $this->config->getHomeDirectory() . DIRECTORY_SEPARATOR . '.ssh';
        for ($i = 2; \file_exists($sshDir . DIRECTORY_SEPARATOR . $basename); $i++) {
            $basename .= $i;
        }

        return $questionHelper->askInput('Enter a filename for the new key (relative to ~/.ssh)', $basename, [], function ($path) use ($sshDir) {
            if (\substr($path, 0, 1) !== '/') {
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
