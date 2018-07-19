<?php
/**
 * @file
 * Override Symfony Console's ListCommand to customize the list's appearance.
 */

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Console\CustomTextDescriptor;
use Psy\Output\ProcOutputPager;
use Psy\Output\ShellOutput;
use Symfony\Component\Console\Command\ListCommand as ParentListCommand;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

class ListCommand extends ParentListCommand
{

    protected function configure()
    {
        parent::configure();
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Show all commands, including hidden ones');
        $this->addOption('pager', null, InputOption::VALUE_REQUIRED, 'Set the pager command', 'less -R -F');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $helper = new DescriptorHelper();
        $helper->register('txt', new CustomTextDescriptor());

        if ($output->isDecorated()
            && $output instanceof StreamOutput
            && $input->getOption('pager')
            && getenv('PAGER') !== ''
            && (!function_exists('posix_isatty') || posix_isatty($output->getStream()))) {

            // Create a pager.
            $pager = new ProcOutputPager($output, $input->getOption('pager'));

            // Create an output object for the pager.
            $pagerOutput = new ShellOutput($output->getVerbosity(), $output->isDecorated(), $output->getFormatter(), $pager);

            // Replace the main output object with a buffer.
            $output = new BufferedOutput($output->getVerbosity(), $output->isDecorated(), $output->getFormatter());
        }

        $helper->describe(
            $output,
            $this->getApplication(),
            [
                'format' => $input->getOption('format'),
                'raw_text' => $input->getOption('raw'),
                'namespace' => $input->getArgument('namespace'),
                'all' => $input->getOption('all'),
            ]
        );

        // If paging is enabled, send buffered output to the pager.
        if (isset($pagerOutput) && $output instanceof BufferedOutput) {
            $pagerOutput->page($output->fetch());
        }
    }
}
