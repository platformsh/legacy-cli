<?php

namespace Platformsh\Cli\Command\Repo;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Exception\GitObjectTypeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LsCommand extends CommandBase
{
    const COMMIT_OPTION_HELP = 'The Git commit SHA. This can also accept the "HEAD" substitution, and caret (^) or tilde (~) suffixes to denote parent commits. No other "git rev-list" syntax is supported.';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('repo:ls')
            ->setDescription('List files in the project repository')
            ->addArgument('path', InputArgument::OPTIONAL, 'The path to a subdirectory')
            ->addOption('directories', 'd', InputOption::VALUE_NONE, 'Show directories only')
            ->addOption('files', 'f', InputOption::VALUE_NONE, 'Show files only')
            ->addOption('git-style', null, InputOption::VALUE_NONE, 'Style output similar to "git ls-tree"')
            ->addOption('commit', 'c', InputOption::VALUE_REQUIRED, self::COMMIT_OPTION_HELP);
        $this->addProjectOption();
        $this->addEnvironmentOption();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        try {
            $tree = $this->api()->getTree($this->getSelectedEnvironment(), $input->getArgument('path'), $input->getOption('commit'));
        } catch (GitObjectTypeException $e) {
            $this->stdErr->writeln(sprintf(
                '%s: <error>%s</error>',
                $e->getMessage(),
                $e->getPath()
            ));
            $this->stdErr->writeln(sprintf('To read a file, run: <comment>%s repo:cat [path]</comment>', $this->config()->get('application.executable')));

            return 3;
        }
        if ($tree == false) {
            $this->stdErr->writeln(sprintf('Directory not found: <error>%s</error>', $input->getArgument('path')));

            return 2;
        }

        $treeObjects = $tree->tree;
        if ($input->getOption('files') && $input->getOption('directories')) {
            // No filters required.
        } elseif ($input->getOption('files')) {
            $treeObjects = array_filter($treeObjects, function (array $treeObject) {
                return $treeObject['type'] === 'blob';
            });
        } elseif ($input->getOption('directories')) {
            $treeObjects = array_filter($treeObjects, function (array $treeObject) {
                return $treeObject['type'] === 'tree';
            });
        }

        $gitStyle = $input->getOption('git-style');
        foreach ($treeObjects as $object) {
            if ($gitStyle) {
                $detailsFormat = "%s %s %s\t%s";
                $output->writeln(sprintf(
                    $detailsFormat,
                    $object['mode'],
                    $object['type'],
                    $object['sha'],
                    $object['path']
                ));
            } else {
                $format = '%s';
                if ($object['type'] === 'tree') {
                    $format = '<fg=cyan>%s/</>';
                }
                $output->writeln(sprintf($format, $object['path']));
            }
        }

        return 0;
    }
}
