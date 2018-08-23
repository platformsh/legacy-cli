<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Stecman\Component\Symfony\Console\BashCompletion\Completion\CompletionAwareInterface;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionContext;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentSynchronizeCommand extends CommandBase implements CompletionAwareInterface
{

    protected function configure()
    {
        $this
            ->setName('environment:synchronize')
            ->setAliases(['sync'])
            ->setDescription("Synchronize an environment's code and/or data from its parent")
            ->addArgument('synchronize', InputArgument::IS_ARRAY, 'What to synchronize: "code", "data" or both')
            ->addOption('rebase', null, InputOption::VALUE_NONE, 'Synchronize code by rebasing instead of merging');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addWaitOptions();
        $this->setHelp(<<<EOT
This command synchronizes to a child environment from its parent environment.

Synchronizing "code" means there will be a Git merge from the parent to the
child. Synchronizing "data" means that all files in all services (including
static files, databases, logs, search indices, etc.) will be copied from the
parent to the child.
EOT
        );
        $this->addExample('Synchronize data from the parent environment', 'data');
        $this->addExample('Synchronize code and data from the parent environment', 'code data');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $selectedEnvironment = $this->getSelectedEnvironment();
        $environmentId = $selectedEnvironment->id;

        if (!$selectedEnvironment->operationAvailable('synchronize', true)) {
            $this->stdErr->writeln(
                "Operation not available: The environment <error>$environmentId</error> can't be synchronized."
            );

            return 1;
        }

        $parentId = $selectedEnvironment->parent;

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $rebase = (bool) $input->getOption('rebase');

        if ($synchronize = $input->getArgument('synchronize')) {
            // The input was invalid.
            if (array_diff($input->getArgument('synchronize'), ['code', 'data', 'both'])) {
                $this->stdErr->writeln("Specify 'code', 'data', or 'both'");
                return 1;
            }
            $syncCode = in_array('code', $synchronize) || in_array('both', $synchronize);
            $syncData = in_array('data', $synchronize) || in_array('both', $synchronize);

            if ($rebase && !$syncCode) {
                $this->stdErr->writeln('<comment>Note:</comment> you specified the <comment>--rebase</comment> option, but this only applies to synchronizing code, which you have not selected.');
                $this->stdErr->writeln('');
            }

            $toSync = $syncCode && $syncData
                ? '<options=underscore>code</> and <options=underscore>data</>'
                : '<options=underscore>' . ($syncCode ? 'code' : 'data') . '</>';

            $confirmText = sprintf(
                'Are you sure you want to synchronize %s from <info>%s</info> to <info>%s</info>?',
                $toSync,
                $parentId,
                $environmentId
            );
            if (!$questionHelper->confirm($confirmText)) {
                return 1;
            }
        } else {
            $syncCode = $questionHelper->confirm(
                "Do you want to synchronize <options=underscore>code</> from <info>$parentId</info> to <info>$environmentId</info>?",
                false
            );

            if ($syncCode && !$rebase) {
                $rebase = $questionHelper->confirm(
                    "Do you want to synchronize code by rebasing instead of merging?",
                    false
                );
            }

            if ($rebase && !$syncCode) {
                $this->stdErr->writeln('<comment>Note:</comment> you specified the <comment>--rebase</comment> option, but this only applies to synchronizing code.');
            }

            $this->stdErr->writeln('');

            $syncData = $questionHelper->confirm(
                "Do you want to synchronize <options=underscore>data</> from <info>$parentId</info> to <info>$environmentId</info>?",
                false
            );
        }
        if (!$syncCode && !$syncData) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('You did not select anything to synchronize.');

            return 1;
        }

        $this->stdErr->writeln("Synchronizing environment <info>$environmentId</info>");

        $activity = $selectedEnvironment->synchronize($syncData, $syncCode, $rebase);
        if ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitAndLog(
                $activity,
                "Synchronization complete",
                "Synchronization failed"
            );
            if (!$success) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * {@inheritdoc}
     */
    public function completeArgumentValues($argumentName, CompletionContext $context)
    {
        if ($argumentName === 'synchronize') {
            return ['code', 'data', 'both'];
        }

        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function completeOptionValues($argumentName, CompletionContext $context)
    {
        return [];
    }
}
