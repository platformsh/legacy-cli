<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Repo;

use Symfony\Contracts\Service\Attribute\Required;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\GitDataApi;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RepoCommandBase extends CommandBase
{
    private GitDataApi $gitDataApi;

    #[Required]
    public function autowire(GitDataApi $gitDataApi): void
    {
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
     * @param string $path
     * @param Environment $environment
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function cat(string $path, Environment $environment, InputInterface $input, OutputInterface $output): int
    {
        $content = $this->gitDataApi->readFile($path, $environment, $input->getOption('commit'));
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
     * @param string $path
     * @param Environment $environment
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function ls(string $path, Environment $environment, InputInterface $input, OutputInterface $output): int
    {
        $tree = $this->gitDataApi->getTree($environment, $path, $input->getOption('commit'));
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
                    $object['path'],
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
