<?php
/**
 * @file
 * Override Symfony Console's HelpCommand to customize the appearance of help.
 */

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\CustomTextDescriptor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\HelpCommand as ParentHelpCommand;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class HelpCommand extends ParentHelpCommand
{

    protected $command;

    /**
     * @inheritdoc
     */
    public function setCommand(Command $command)
    {
        $this->command = $command;
    }

    /**
     * @inheritdoc
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (null === $this->command) {
            $this->command = $this->getApplication()->find($input->getArgument('command_name'));
        }

        if ($input->getOption('xml')) {
            $input->setOption('format', 'xml');
        }

        $helper = new DescriptorHelper();
        $helper->register('txt', new CustomTextDescriptor());
        $helper->describe($output, $this->command, array(
          'format' => $input->getOption('format'),
          'raw'    => $input->getOption('raw'),
        ));
    }

}
