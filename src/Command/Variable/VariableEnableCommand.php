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
    protected $stability = 'deprecated';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('variable:enable')
            ->addArgument('name', InputArgument::REQUIRED, 'The name of the variable')
            ->setDescription('Enable a disabled environment-level variable');
        $this->setHelp(
            'This command is deprecated and will be removed in a future version.'
            . "\nInstead, use: <info>variable:update --enabled false [variable]</info>"
        );
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
            ] + array_filter([
                '--wait' => $input->getOption('wait'),
                '--no-wait' => $input->getOption('no-wait'),
            ]));
    }
}
