<?php

namespace Platformsh\Cli\Command\Commit;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\GitDataApi;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Git\Commit;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class CommitListCommand extends CommandBase
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('commit:list')
            ->setAliases(['commits'])
            ->setDescription('List commits')
            ->addArgument('commit', InputOption::VALUE_REQUIRED, 'The starting Git commit SHA. ' . GitDataApi::COMMIT_SYNTAX_HELP)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'The number of commits to display.', 10);
        $this->addProjectOption();
        $this->addEnvironmentOption();

        $definition = $this->getDefinition();
        Table::configureInput($definition);
        PropertyFormatter::configureInput($definition);

        $this->addExample('Display commits on an environment');
        $this->addExample('Display commits starting from two before the current one', 'HEAD~2');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, false, true);
        $environment = $this->getSelectedEnvironment();

        $startSha = $input->getArgument('commit');
        /** @var \Platformsh\Cli\Service\GitDataApi $gitData */
        $gitData = $this->getService('git_data_api');
        $parent = $gitData->getCommit($environment, $startSha);
        if (!$parent) {
            if ($startSha) {
                $this->stdErr->writeln('Commit not found: <error>' . $startSha . '</error>');
            } else {
                $this->stdErr->writeln('No commits found.');
            }

            return 1;
        }

        /** @var Commit[] $commits */
        $commits = [$parent];

        if ($output->isDecorated()) {
            $this->stdErr->writeln(sprintf(
                'Commits on the project %s, environment %s:',
                $this->api()->getProjectLabel($this->getSelectedProject()),
                $this->api()->getEnvironmentLabel($environment)
            ));
        }

        $progress = new ProgressBar($output->isDecorated() ? $this->stdErr : new NullOutput());
        $progress->setMessage('Loading...');
        $progress->setFormat('%message% %current% (limit: %max%)');
        $limit = $input->getOption('limit');
        $progress->start($limit);
        for ($current = $parent;
             count($current->parents) && count($commits) < $limit;) {
            foreach (array_reverse($current->parents) as $parentSha) {
                if (!isset($commits[$parentSha])) {
                    $commits[$parentSha] = $gitData->getCommit($environment, $parentSha);
                }
                $current = $commits[$parentSha];
                $progress->advance();
            }
        }
        $progress->clear();

        /** @var PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $header = ['Date', 'SHA', 'Author', 'Summary'];
        $rows = [];
        foreach ($commits as $commit) {
            $row = [];
            $row[] = $formatter->format($commit->author['date'], 'author.date');
            $row[] = new AdaptiveTableCell($commit->sha, ['wrap' => false]);
            $row[] = $commit->author['name'];
            $row[] = $this->summarize($commit->message);
            $rows[] = $row;
        }

        /** @var Table $table */
        $table = $this->getService('table');

        $table->render($rows, $header);

        return 0;
    }

    /**
     * Summarize a commit message.
     *
     * @param string $message
     *
     * @return string
     */
    private function summarize($message)
    {
        $message = ltrim($message, "\n");
        if ($newLinePos = strpos($message, "\n")) {
            $message = substr($message, 0, $newLinePos);
        }

        return rtrim($message);
    }
}
