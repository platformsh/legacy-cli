<?php
namespace Platformsh\Cli\Command\App;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\AppConfig;
use Platformsh\Cli\Model\Host\LocalHost;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:config-get', description: 'View the configuration of an app')]
class AppConfigGetCommand extends CommandBase
{
    public function __construct(private readonly Config $config, private readonly PropertyFormatter $propertyFormatter)
    {
        parent::__construct();
    }
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
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
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Allow override via PLATFORM_APPLICATION.
        $prefix = $this->config->get('service.env_prefix');
        if (getenv($prefix . 'APPLICATION') && !LocalHost::conflictsWithCommandLineOptions($input, $prefix)) {
            $this->debug('Reading application config from environment variable ' . $prefix . 'APPLICATION');
            $decoded = json_decode(base64_decode(getenv($prefix . 'APPLICATION'), true), true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Failed to decode: ' . $prefix . 'APPLICATION');
            }
            $appConfig = new AppConfig($decoded);
        } else {
            $this->chooseEnvFilter = $this->filterEnvsMaybeActive();
            $this->validateInput($input);
            $this->warnAboutDeprecatedOptions(['identity-file']);

            $appConfig = $this->selectRemoteContainer($input, false)
                ->getConfig();
        }

        /** @var PropertyFormatter $formatter */
        $formatter = $this->propertyFormatter;
        $formatter->displayData($output, $appConfig->getNormalized(), $input->getOption('property'));
        return 0;
    }
}
