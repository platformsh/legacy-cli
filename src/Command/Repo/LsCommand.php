<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Repo;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Selector;
use Platformsh\Client\Exception\GitObjectTypeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class LsCommand extends CommandBase
{
    protected static $defaultName = 'repo:ls';

    private $api;
    private $config;
    private $selector;

    public function __construct(
        Api $api,
        Config $config,
        Selector $selector
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->selector = $selector;
        parent::__construct();
    }


    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('List files in the project repository')
            ->addArgument('path', InputArgument::OPTIONAL, 'The path to a subdirectory')
            ->addOption('directories', 'd', InputOption::VALUE_NONE, 'Show directories only')
            ->addOption('files', 'f', InputOption::VALUE_NONE, 'Show files only')
            ->addOption('git-style', null, InputOption::VALUE_NONE, 'Style output similar to "git ls-tree"');

        $definition = $this->getDefinition();
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);

    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $environment = $this->selector->getSelection($input)->getEnvironment();
        try {
            $tree = $this->api->getTree($environment, $input->getArgument('path'));
        } catch (GitObjectTypeException $e) {
            $this->stdErr->writeln(sprintf(
                '%s: <error>%s</error>',
                $e->getMessage(),
                $e->getPath()
            ));
            $this->stdErr->writeln(sprintf('To read a file, run: <comment>%s repo:cat [path]</comment>', $this->config->get('application.executable')));

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
