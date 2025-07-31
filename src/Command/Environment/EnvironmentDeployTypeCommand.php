<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentDeployTypeCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('environment:deploy:type')
            ->setDescription('Show or set the environment deployment type')
            ->addArgument('type', InputArgument::OPTIONAL, 'The environment deployment type: automatic or manual.')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the deployment type to stdout');
        $this->addProjectOption()
            ->addEnvironmentOption();
        $this->addWaitOptions();
        $this->addExample('Set the deployment type to "manual" (disable automatic deployments)', 'manual');
        $this->setHelp("Choose <info>automatic</info> (the default) if you want your changes to be deployed immediately as they are made."
            ."\nChoose <info>manual</info> to have changes staged until you trigger a deployment (including changes to code, variables, domains and settings).");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->chooseEnvFilter = $this->filterEnvsByStatus(['active', 'paused']);
        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();
        $settings = $environment->getSettings();
        $currentType = $settings->enable_manual_deployments ? 'manual' : 'automatic';

        $newType = $input->getArgument('type');
        if ($newType === null) {
            if ($input->getOption('pipe')) {
                $output->writeln($currentType);
                return 0;
            }
            $this->ensurePrintSelectedEnvironment(true);
            $this->stdErr->writeln(sprintf('Deployment type: <info>%s</info>', $currentType));
            return 0;
        }

        if ($newType !== 'manual' && $newType !== 'automatic') {
            throw new InvalidArgumentException(sprintf('Invalid value "%s": the deployment type must be one of "automatic" or "manual".', $newType));
        }

        $this->ensurePrintSelectedEnvironment(true);

        if ($newType === $currentType) {
            $this->stdErr->writeln(sprintf('The deployment type is already <info>%s</info>.', $currentType));
            return 0;
        }

        if ($newType === 'manual' && !$environment->isActive()) {
            $this->stdErr->writeln('The <comment>manual</comment> deployment type is not available as the environment is not active.');
            return 0;
        }

        $this->stdErr->writeln(sprintf('Changing the deployment type from <info>%s</info> to <info>%s</info>...',
            $currentType, $newType));

        if ($newType == 'automatic') {
            $activities = $environment->getActivities(0, null, null, Activity::STATE_STAGED);
            if (count($activities) > 0) {
                $this->stdErr->writeln('Updating this setting will immediately deploy staged changes.');
                /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
                $questionHelper = $this->getService('question_helper');
                if (!$questionHelper->confirm('Are you sure you want to continue?')) {
                    return 1;
                }
            }
        }

        $result = $settings->update(['enable_manual_deployments' => $newType === 'manual']);

        if ($result->getActivities() && $this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
            if (!$success) {
                return 1;
            }
        }

        $this->stdErr->writeln(sprintf('The deployment type was updated successfully to: <info>%s</info>', $newType));

        return 0;
    }
}
