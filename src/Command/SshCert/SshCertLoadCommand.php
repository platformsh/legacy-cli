<?php
namespace Platformsh\Cli\Command\SshCert;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\SshConfig;
use Platformsh\Cli\SshCert\Certificate;
use Platformsh\Cli\SshCert\Certifier;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SshCertLoadCommand extends CommandBase
{
    protected static $defaultName = 'ssh-cert:load';
    protected static $defaultDescription = 'Generate an SSH certificate';

    private $api;
    private $certifier;
    private $config;
    private $formatter;
    private $sshConfig;

    public function __construct(Api $api, Certifier $certifier, Config $config, PropertyFormatter $formatter, SshConfig $sshConfig)
    {
        $this->api = $api;
        $this->certifier = $certifier;
        $this->config = $config;
        $this->formatter = $formatter;
        $this->sshConfig = $sshConfig;
        parent::__construct();
    }


    protected function configure()
    {
        $this->addOption('refresh-only', null, InputOption::VALUE_NONE, 'Only refresh the certificate, if necessary (do not write SSH config)')
            ->addOption('new', null, InputOption::VALUE_NONE, 'Force the certificate to be refreshed')
            ->addOption('new-key', null, InputOption::VALUE_NONE, '[Deprecated] Use --new instead');
        $help = 'This command checks if a valid SSH certificate is present, and generates a new one if necessary.';
        if ($this->config->get('api.auto_load_ssh_cert')) {
            $envPrefix = $this->config->get('application.env_prefix');
            $help .= "\n\nCertificates allow you to make SSH connections without having previously uploaded a public key. They are more secure than keys and they allow for other features."
                . "\n\nNormally the certificate is loaded automatically during login, or when making an SSH connection. So this command is seldom needed."
                . "\n\nIf you want to set up certificates without login and without an SSH-related command, for example if you are writing a script that uses an API token via an environment variable, then you would probably want to run this command explicitly."
                . " For unattended scripts, remember to turn off interaction via --yes or the {$envPrefix}NO_INTERACTION environment variable.";
        }
        $this->setHelp(\wordwrap($help));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // TODO
        //$this->warnAboutDeprecatedOptions(['new-key'], 'The --new-key option is deprecated. Use --new instead.');

        $sshCert = $this->certifier->getExistingCertificate();

        $refresh = true;
        if ($sshCert
            && !$input->getOption('new')
            && !$input->getOption('new-key')
            && !$sshCert->hasExpired()
            && $sshCert->metadata()->getKeyId() === $this->api->getMyUserId()) {
            $this->stdErr->writeln('A valid SSH certificate exists');
            $this->displayCertificate($sshCert);
            $refresh = false;
        }

        if ($refresh) {
            if (!$this->sshConfig->checkRequiredVersion()) {
                return 1;
            }
            $this->stdErr->writeln('Generating SSH certificate...');
            $sshCert = $this->certifier->generateCertificate();
            $this->displayCertificate($sshCert);
        }

        $hasSessionConfig = $this->sshConfig->configureSessionSsh();

        if ($input->getOption('refresh-only')) {
            return 0;
        }

        $success = !$hasSessionConfig || $this->sshConfig->addUserSshConfig();

        return $success ? 0 : 1;
    }

    private function displayCertificate(Certificate $cert)
    {
        $expires = $this->formatter->formatDate($cert->metadata()->getValidBefore());
        $expiresWithColor = $expires < time() ? '<fg=green>' . $expires . '</>' : $expires;
        $mfaWithColor = $cert->hasMfa() ? '<fg=green>verified</>' : 'not verified';
        $interactivityMode = $cert->isApp() ? 'app' : 'interactive';
        $this->stdErr->writeln([
            "  Expires at: $expiresWithColor",
            "  Multi-factor authentication: $mfaWithColor",
            "  Mode: <info>$interactivityMode</info>",
        ]);
        $this->stdErr->writeln('The certificate will be automatically refreshed when necessary.');
    }
}
