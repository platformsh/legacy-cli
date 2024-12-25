<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'environment:init', description: 'Initialize an environment from a public Git repository')]
class EnvironmentInitCommand extends CommandBase
{
    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly Config $config, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('url', InputArgument::REQUIRED, 'A URL to a Git repository')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'The name of the profile');

        if ($this->config->getStr('service.name') === 'Platform.sh') {
            $this->addExample('Initialize using the Platform.sh Go template', 'https://github.com/platformsh-templates/golang');
        }

        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(envRequired: false, selectDefaultEnv: true));

        $environment = $selection->getEnvironment();

        $url = $input->getArgument('url');
        $profile = $input->getOption('profile') ?: basename((string) $url);

        if (parse_url((string) $url) === false) {
            $this->stdErr->writeln(sprintf('Invalid repository URL: <error>%s</error>', $url));

            return 1;
        }

        if (!$environment->operationAvailable('initialize', true)) {
            $this->stdErr->writeln(sprintf(
                "Operation not available: The environment <error>%s</error> can't be initialized.",
                $environment->id,
            ));

            if ($environment->has_code) {
                $this->stdErr->writeln('The environment already contains code.');
            }

            return 1;
        }

        // Summarize this action with a message.
        $message = 'Initializing project ';
        $message .= $this->api->getProjectLabel($selection->getProject());
        $message .= ', environment ' . $this->api->getEnvironmentLabel($environment);
        if ($input->getOption('profile')) {
            $message .= ' with profile <info>' . $profile . '</info> (' . $url . ')';
        } else {
            $message .= ' with repository <info>' . $url . '</info>.';
        }
        $this->stdErr->writeln($message);

        $result = $environment->runOperation('initialize', 'POST', ['profile' => $profile, 'repository' => $url]);

        $this->api->clearEnvironmentsCache($environment->project);

        if ($this->activityMonitor->shouldWait($input)) {
            $activityMonitor = $this->activityMonitor;
            $success = $activityMonitor->waitMultiple($result->getActivities(), $selection->getProject());
            return $success ? 0 : 1;
        }

        return 0;
    }
}
