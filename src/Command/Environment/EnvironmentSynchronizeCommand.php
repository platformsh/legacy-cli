<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Stecman\Component\Symfony\Console\BashCompletion\Completion\CompletionAwareInterface;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionContext;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentSynchronizeCommand extends CommandBase implements CompletionAwareInterface
{
    protected static $defaultName = 'environment:synchronize';

    private $api;
    private $activityMonitor;
    private $questionHelper;
    private $selector;

    public function __construct(
        Api $api,
        ActivityMonitor $activityMonitor,
        QuestionHelper $questionHelper,
        Selector $selector
    ) {
        $this->api = $api;
        $this->activityMonitor = $activityMonitor;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['sync'])
            ->setDescription("Synchronize an environment's code and/or data from its parent")
            ->addArgument('synchronize', InputArgument::IS_ARRAY, 'What to synchronize: "code", "data" or both');

        $definition = $this->getDefinition();
        $this->selector->addEnvironmentOption($definition);
        $this->selector->addProjectOption($definition);
        $this->activityMonitor->addWaitOptions($definition);

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
        $selection = $this->selector->getSelection($input);

        $selectedEnvironment = $selection->getEnvironment();
        $environmentId = $selectedEnvironment->id;

        if (!$this->api->checkEnvironmentOperation('synchronize', $selectedEnvironment)) {
            $this->stdErr->writeln(
                "Operation not available: The environment <error>$environmentId</error> can't be synchronized."
            );
            if ($selectedEnvironment->is_dirty) {
                $this->api->clearEnvironmentsCache($selectedEnvironment->project);
            }

            return 1;
        }

        $parentId = $selectedEnvironment->parent;

        if ($synchronize = $input->getArgument('synchronize')) {
            // The input was invalid.
            if (array_diff($input->getArgument('synchronize'), ['code', 'data', 'both'])) {
                $this->stdErr->writeln("Specify 'code', 'data', or 'both'");
                return 1;
            }
            $syncCode = in_array('code', $synchronize) || in_array('both', $synchronize);
            $syncData = in_array('data', $synchronize) || in_array('both', $synchronize);
            $confirmText = sprintf(
                'Are you sure you want to synchronize <info>%s</info> to <info>%s</info>?',
                $parentId,
                $environmentId
            );
            if (!$this->questionHelper->confirm($confirmText)) {
                return 1;
            }
        } else {
            $syncCode = $this->questionHelper->confirm(
                "Synchronize code from <info>$parentId</info> to <info>$environmentId</info>?",
                false
            );
            $syncData = $this->questionHelper->confirm(
                "Synchronize data from <info>$parentId</info> to <info>$environmentId</info>?",
                false
            );
        }
        if (!$syncCode && !$syncData) {
            $this->stdErr->writeln("<error>You must synchronize at least code or data.</error>");

            return 1;
        }

        $this->stdErr->writeln("Synchronizing environment <info>$environmentId</info>");

        $activity = $selectedEnvironment->synchronize($syncData, $syncCode);
        if ($this->activityMonitor->shouldWait($input)) {
            $success = $this->activityMonitor->waitAndLog(
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
