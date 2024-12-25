<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\App;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
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
    public function __construct(private readonly Config $config, private readonly Io $io, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The configuration property to view')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->selector->addAppOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->addOption('identity-file', 'i', InputOption::VALUE_REQUIRED, '[Deprecated option, no longer used]');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Allow override via PLATFORM_APPLICATION.
        $prefix = $this->config->getStr('service.env_prefix');
        if (getenv($prefix . 'APPLICATION') && !LocalHost::conflictsWithCommandLineOptions($input, $prefix)) {
            $this->io->debug('Reading application config from environment variable ' . $prefix . 'APPLICATION');
            $decoded = json_decode((string) base64_decode(getenv($prefix . 'APPLICATION'), true), true);
            if (!is_array($decoded)) {
                throw new \RuntimeException('Failed to decode: ' . $prefix . 'APPLICATION');
            }
            $appConfig = new AppConfig($decoded);
        } else {
            $selection = $this->selector->getSelection($input, new SelectorConfig(chooseEnvFilter: SelectorConfig::filterEnvsMaybeActive()));
            $this->io->warnAboutDeprecatedOptions(['identity-file']);
            $appConfig = $selection->getRemoteContainer()->getConfig();
        }
        $this->propertyFormatter->displayData($output, $appConfig->getNormalized(), $input->getOption('property'));
        return 0;
    }
}
