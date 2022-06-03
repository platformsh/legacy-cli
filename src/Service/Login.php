<?php declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\SshCert\Certifier;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Login
{
    private $api;
    private $certifier;
    private $config;
    private $sshConfig;
    private $stdErr;

    public function __construct(
        Api $api,
        Certifier $certifier,
        Config $config,
        OutputInterface $output,
        SshConfig $sshConfig
    )
    {
        $this->api = $api;
        $this->certifier = $certifier;
        $this->config = $config;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $this->sshConfig = $sshConfig;
    }

    /**
     * Finalizes login: refreshes SSH certificate, prints account information.
     */
    public function finalize()
    {
        // Reset the API client so that it will use the new tokens.
        $client = $this->api->getClient(false, true);
        $this->stdErr->writeln('You are logged in.');

        // Generate a new certificate from the certifier API.
        if ($this->certifier->isAutoLoadEnabled() && $this->sshConfig->checkRequiredVersion()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('Generating SSH certificate...');
            try {
                $this->certifier->generateCertificate();
                $this->stdErr->writeln('A new SSH certificate has been generated.');
                $this->stdErr->writeln('It will be automatically refreshed when necessary.');
            } catch (\Exception $e) {
                $this->stdErr->writeln('Failed to generate SSH certificate: <error>' . $e->getMessage() . '</error>');
            }
        }

        // Write SSH configuration.
        if ($this->sshConfig->configureSessionSsh()) {
            $this->sshConfig->addUserSshConfig();
        }

        // Show user account info.
        $info = $client->getAccountInfo();
        $this->stdErr->writeln(sprintf(
            "\nUsername: <info>%s</info>\nEmail address: <info>%s</info>",
            $info['username'],
            $info['mail']
        ));
    }

    /**
     * Get help on how to use API tokens non-interactively.
     *
     * @param string $tag
     *
     * @return string
     */
    public function getNonInteractiveAuthHelp(string $tag = 'info'): string
    {
        $prefix = $this->config->get('application.env_prefix');

        return "To authenticate non-interactively, configure an API token using the <$tag>${prefix}TOKEN</$tag> environment variable.";
    }
}
