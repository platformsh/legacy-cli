<?php
/**
 * @file
 * Override Symfony Console's ListCommand to customize the list's appearance.
 */

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Console\CustomTextDescriptor;
use Platformsh\Cli\Service\Config;
use Psy\Output\ProcOutputPager;
use Psy\Output\ShellOutput;
use Symfony\Component\Console\Command\ListCommand as ParentListCommand;
use Symfony\Component\Console\Helper\DescriptorHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

class ListCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('list')
            ->setDescription('Lists commands')
            ->addArgument('namespace', InputArgument::OPTIONAL, 'The namespace name')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Output a raw command list')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'The output format (txt, xml, json, or md)', 'txt')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Show all commands, including hidden ones');

        $this->addExample('List only environment-related commands', 'env');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $helper = new DescriptorHelper();
        $helper->register('txt', new CustomTextDescriptor());

        $pager = $this->config()->getWithDefault('pagination.command', null);
        if ($pager === null && \getenv('PAGER') !== false) {
            $pager = \getenv('PAGER');
        }

        if ($pager !== ''
            && $this->config()->getWithDefault('pagination.enabled', true)
            && $output->isDecorated()
            && $output instanceof StreamOutput
            && (!function_exists('posix_isatty') || posix_isatty($output->getStream()))) {
            if ($pager === 'less') {
                $pager = 'less -F';
            }

            // Create a pager.
            $pager = new ProcOutputPager($output, $pager);

            // Create an output object for the pager.
            $pagerOutput = new ShellOutput($output->getVerbosity(), $output->isDecorated(), $output->getFormatter(), $pager);
        }

        $doList = function (OutputInterface $output) use ($helper, $input) {
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
        };

        if (isset($pagerOutput)) {
            $pagerOutput->page($doList);
        } else {
            $doList($output);
        }
    }
}
