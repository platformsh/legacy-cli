<?php

namespace Platformsh\Cli\Command\Repo;

use Platformsh\Cli\Service\Config;
use Symfony\Contracts\Service\Attribute\Required;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\GitDataApi;
use Platformsh\Client\Exception\GitObjectTypeException;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RepoCommandBase extends CommandBase
{
    private readonly GitDataApi $gitDataApi;
    private readonly Config $config;
    #[Required]
    public function autowire(Config $config, GitDataApi $gitDataApi) : void
    {
        $this->config = $config;
        $this->gitDataApi = $gitDataApi;
    }
    /**
     * Adds the --commit (-c) command option.
     */
    protected function addCommitOption(): static
    {
        $this->addOption('commit', 'c', InputOption::VALUE_REQUIRED, 'The commit SHA. ' . GitDataApi::COMMIT_SYNTAX_HELP);
        return $this;
    }

    /**
     * Reads a file in a repository using the Git Data API.
     *
     * @param Environment $environment
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function cat(Environment $environment, InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('path');
        try {
            $gitData = $this->gitDataApi;
            $content = $gitData->readFile($path, $environment, $input->getOption('commit'));
        } catch (GitObjectTypeException $e) {
            $this->stdErr->writeln(sprintf(
                '%s: <error>%s</error>',
                $e->getMessage(),
                $e->getPath()
            ));
            $this->stdErr->writeln(sprintf('To list directory contents, run: <comment>%s repo:ls [path]</comment>', $this->config->get('application.executable')));

            return 3;
        }
        if ($content === false) {
            $this->stdErr->writeln(sprintf('File not found: <error>%s</error>', $path));

            return 2;
        }

        $output->write($content, false, OutputInterface::OUTPUT_RAW);

        return 0;
    }

    /**
     * Lists files in a tree using the Git Data API.
     *
     * @param Environment $environment
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function ls(Environment $environment, InputInterface $input, OutputInterface $output): int
    {
        try {
            $gitData = $this->gitDataApi;
            $tree = $gitData->getTree($environment, $input->getArgument('path'), $input->getOption('commit'));
        } catch (GitObjectTypeException $e) {
            $this->stdErr->writeln(sprintf(
                '%s: <error>%s</error>',
                $e->getMessage(),
                $e->getPath()
            ));
            $this->stdErr->writeln(sprintf('To read a file, run: <comment>%s repo:cat [path]</comment>', $this->config->get('application.executable')));

            return 3;
        }
        if (!$tree) {
            $this->stdErr->writeln(sprintf('Directory not found: <error>%s</error>', $input->getArgument('path')));

            return 2;
        }

        $treeObjects = $tree->tree;
        if ($input->hasOption('files') && $input->hasOption('directories')) {
            if ($input->getOption('files') && !$input->getOption('directories')) {
                $treeObjects = array_filter($treeObjects, fn(array $treeObject): bool => $treeObject['type'] === 'blob');
            } elseif ($input->getOption('directories') && !$input->getOption('files')) {
                $treeObjects = array_filter($treeObjects, fn(array $treeObject): bool => $treeObject['type'] === 'tree');
            }
        }

        $gitStyle = $input->hasOption('git-style') && $input->getOption('git-style');
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
