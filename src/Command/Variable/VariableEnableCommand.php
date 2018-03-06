<?php
namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @deprecated Use "variable:update --enabled true" instead
 */
class VariableEnableCommand extends CommandBase
{
    protected $hiddenInList = true;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('variable:enable')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the variable')
            ->setDescription('Enable a disabled environment-level variable');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addWaitOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        return $this->runOtherCommand('variable:update', [
            'name' => $input->getArgument('name'),
            '--enabled' => 'true',
            '--project' => $this->getSelectedProject()->id,
            '--environment' => $this->getSelectedEnvironment()->id,
        ]);
    }
}
