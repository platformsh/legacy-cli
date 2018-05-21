<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Exception\OperationUnavailableException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentInitCommand extends CommandBase
{
    protected static $defaultName = 'environment:init';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('url', InputArgument::REQUIRED, 'A URL to a Git repository')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'The name of the profile');
        $this->setHidden(true);
        $this->addProjectOption()
            ->addEnvironmentOption()
            ->addWaitOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, true);
        if (!$this->hasSelectedEnvironment()) {
            $this->selectEnvironment('master');
        }

        $environment = $this->getSelectedEnvironment();

        $url = $input->getArgument('url');
        $profile = $input->getOption('profile') ?: basename($url);

        if (parse_url($url) === false) {
            $this->stdErr->writeln(sprintf('Invalid repository URL: <error>%s</error>', $url));

            return 1;
        }

        try {
            $activity = $environment->initialize($profile, $url);
        } catch (OperationUnavailableException $e) {
            if ($environment->has_code) {
                $this->stdErr->writeln(sprintf('The environment <error>%s</error> cannot be initialized: it already contains code.', $environment->id));

                return 1;
            }

            throw $e;
        }

        $this->api()->clearEnvironmentsCache($environment->project);

        if ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $activityMonitor->waitAndLog($activity);
        }

        return 0;
    }
}
