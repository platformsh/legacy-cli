<?php
namespace Platformsh\Cli\Command\App;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\AppConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppConfigGetCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('app:config-get')
            ->setDescription('View the configuration of an app')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The configuration property to view')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');
        $this->addProjectOption();
        $this->addEnvironmentOption();
        $this->addAppOption();
        $this->addOption('identity-file', 'i', InputOption::VALUE_REQUIRED, '[Deprecated option, no longer used]');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Allow override via PLATFORM_APPLICATION.
        $prefix = $this->config()->get('service.env_prefix');
        if (getenv($prefix . 'APPLICATION') && !$this->doesEnvironmentConflictWithCommandLine($input)) {
            $this->debug('Reading application config from environment variable ' . $prefix . 'APPLICATION');
            $decoded = json_decode(base64_decode(getenv($prefix . 'APPLICATION'), true), true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Failed to decode: ' . $prefix . 'APPLICATION');
            }
            $appConfig = new AppConfig($decoded);
        } else {
            $this->validateInput($input);
            $this->warnAboutDeprecatedOptions(['identity-file']);

            $appConfig = $this->selectRemoteContainer($input, false)
                ->getConfig();
        }

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');
        $formatter->displayData($output, $appConfig->getNormalized(), $input->getOption('property'));
    }
}
