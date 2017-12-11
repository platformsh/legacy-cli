<?php
namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VariableDisableCommand extends CommandBase
{
    protected $hiddenInList = true;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('variable:disable')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the variable')
            ->setDescription('Disable an enabled environment-level variable');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addNoWaitOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $name = $input->getArgument('name');

        $variable = $this->getSelectedEnvironment()
                         ->getVariable($name);
        if (!$variable) {
            $this->stdErr->writeln("Variable not found: <error>$name</error>");

            return 1;
        }

        if (!$variable->is_enabled) {
            $this->stdErr->writeln("The variable is already disabled: <info>$name</info>");

            return 0;
        }

        $this->stdErr->writeln(sprintf(
            'Disabling variable <info>%s</info> on environment <info>%s</info>',
            $variable->name,
            $variable->environment
        ));
        $result = $variable->update(['is_enabled' => false]);

        $success = true;
        if (!$result->countActivities()) {
            $this->redeployWarning();
        } elseif (!$input->getOption('no-wait')) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
        }

        return $success ? 0 : 1;
    }
}
