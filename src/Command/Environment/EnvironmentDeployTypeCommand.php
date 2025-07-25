<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentDeployTypeCommand extends CommandBase
{
    const HINT = "<fg=yellow>Hint</>: Choose <info>automatic (default)</info> if you want your changes to be deployed immediately as they are made.\nChoose <info>manual</info> to have code, variables, domains, and settings changes staged until you trigger a deployment.";

    protected function configure()
    {
        $this
            ->setName('environment:deploy:type')
            ->addArgument('type', InputArgument::OPTIONAL, 'The environment deployment type, automatic or manual.')
            ->setDescription('Show or set the environment deployment type');
        $this->addProjectOption()
            ->addEnvironmentOption();
        $this->addWaitOptions();
        $this->addExample('Set manual deployment type.', 'manual');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->chooseEnvFilter = $this->filterEnvsByStatus(['active', 'paused']);
        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();
        $currentSettings = $environment->getSettings();

        if ($type = $input->getArgument('type')) {
            if ($type !== 'manual' && $type !== 'automatic') {
                throw new InvalidArgumentException(sprintf("Invalid value %s. Deployment type can be either automatic or manual.", $type));
            }

            if ($currentSettings->enable_manual_deployments === ($type === 'manual')) {
                $this->stdErr->writeln(sprintf("The deployment type is already %s.", $type));
                return 0;
            }

            if ($type == 'automatic') {
                $activities = $environment->getActivities(0, null, null, Activity::STATE_STAGED);
                if (count($activities) > 0) {
                    /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
                    $questionHelper = $this->getService('question_helper');
                    if (!$questionHelper->confirm("Updating this setting will immediately start a deployment to apply all staged changes.\nAre you sure you want to proceed?")) {
                        return 1;
                    }
                }
            } else {
                if (!$environment->operationAvailable('deploy', true) || !$environment->isActive()) {
                    $this->stdErr->writeln("Manual deployment type is not available for this environment.");
                    return 0;
                }
            }

            $result = $environment->setManualDeployments($type === 'manual');
            $settings = $result->getEntity();

            $this->stdErr->writeln('Success!');
            $this->stdErr->writeln(sprintf("<fg=yellow>Deployment type</>: %s\n\n%s",
                $settings->enable_manual_deployments ? 'manual' : 'automatic', self::HINT));
        } else {
            $this->stdErr->writeln(sprintf("<fg=yellow>Deployment type</>: %s\n\n%s",
                $currentSettings->enable_manual_deployments ? 'manual' : 'automatic', self::HINT));
            return 0;
        }

        return 0;
    }
}
