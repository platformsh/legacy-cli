<?php

namespace Platformsh\Cli\Command\Commit;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\GitDataApi;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Git\Commit;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

class CommitListCommand extends CommandBase
{

    private $tableHeader = ['Date', 'SHA', 'Author', 'Summary'];

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
        Table::configureInput($definition, $this->tableHeader);
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
        $startCommit = $gitData->getCommit($environment, $startSha);
        if (!$startCommit) {
            if ($startSha) {
                $this->stdErr->writeln('Commit not found: <error>' . $startSha . '</error>');
            } else {
                $this->stdErr->writeln('No commits found.');
            }

            return 1;
        }

        /** @var Table $table */
        $table = $this->getService('table');

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Commits on the project %s, environment %s:',
                $this->api()->getProjectLabel($this->getSelectedProject()),
                $this->api()->getEnvironmentLabel($environment)
            ));
        }

        $commits = $this->loadCommitList($environment, $startCommit, $input->getOption('limit'));

        /** @var PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $rows = [];
        foreach ($commits as $commit) {
            $row = [];
            $row[] = new AdaptiveTableCell(
                $formatter->format($commit->author['date'], 'author.date'),
                ['wrap' => false]
            );
            $row[] = $commit->sha;
            $row[] = $commit->author['name'];
            $row[] = $this->summarize($commit->message);
            $rows[] = $row;
        }

        $table->render($rows, $this->tableHeader);

        return 0;
    }

    /**
     * Load parent commits, recursively, up to the limit.
     *
     * @param \Platformsh\Client\Model\Environment $environment
     * @param \Platformsh\Client\Model\Git\Commit  $startCommit
     * @param int                                  $limit
     *
     * @return \Platformsh\Client\Model\Git\Commit[]
     */
    private function loadCommitList(Environment $environment, Commit $startCommit, $limit = 10)
    {
        $commits = [$startCommit];
        if (!count($startCommit->parents) || $limit === 1) {
            return $commits;
        }

        /** @var \Platformsh\Cli\Service\GitDataApi $gitData */
        $gitData = $this->getService('git_data_api');

        $progress = new ProgressBar($this->stdErr->isDecorated() ? $this->stdErr : new NullOutput());
        $progress->setMessage('Loading...');
        $progress->setFormat('%message% %current% (limit: %max%)');
        $progress->start($limit);
        for ($currentCommit = $startCommit;
             count($currentCommit->parents) && count($commits) < $limit;) {
            foreach (array_reverse($currentCommit->parents) as $parentSha) {
                if (!isset($commits[$parentSha])) {
                    $commits[$parentSha] = $gitData->getCommit($environment, $parentSha);
                }
                $currentCommit = $commits[$parentSha];
                $progress->advance();
                if (count($commits) >= $limit) {
                    break 2;
                }
            }
        }
        $progress->clear();

        return $commits;
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
