<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Commit;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\GitDataApi;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Git\Commit;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commit:list', description: 'List commits', aliases: ['commits'])]
class CommitListCommand extends CommandBase
{
    /** @var array<string|int, string> */
    private array $tableHeader = ['Date', 'SHA', 'Author', 'Summary'];

    public function __construct(private readonly Api $api, private readonly GitDataApi $gitDataApi, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('commit', InputOption::VALUE_REQUIRED, 'The starting Git commit SHA. ' . GitDataApi::COMMIT_SYNTAX_HELP)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'The number of commits to display.', 10);
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);

        $definition = $this->getDefinition();
        Table::configureInput($definition, $this->tableHeader);
        PropertyFormatter::configureInput($definition);

        $this->addExample('Display commits on an environment');
        $this->addExample('Display commits starting from two before the current one', 'HEAD~2');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(selectDefaultEnv: true));
        $environment = $selection->getEnvironment();

        $startSha = $input->getArgument('commit');
        $startCommit = $this->gitDataApi->getCommit($environment, $startSha);
        if (!$startCommit) {
            if ($startSha) {
                $this->stdErr->writeln('Commit not found: <error>' . $startSha . '</error>');
            } else {
                $this->stdErr->writeln('No commits found.');
            }

            return 1;
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Commits on the project %s, environment %s:',
                $this->api->getProjectLabel($selection->getProject()),
                $this->api->getEnvironmentLabel($environment),
            ));
        }

        $commits = $this->loadCommitList($environment, $startCommit, $input->getOption('limit'));

        $rows = [];
        foreach ($commits as $commit) {
            $row = [];
            $row[] = new AdaptiveTableCell(
                $this->propertyFormatter->format($commit->author['date'], 'author.date'),
                ['wrap' => false],
            );
            $row[] = $commit->sha;
            $row[] = $commit->author['name'];
            $row[] = $this->summarize($commit->message);
            $rows[] = $row;
        }

        $this->table->render($rows, $this->tableHeader);

        return 0;
    }

    /**
     * Load parent commits, recursively, up to the limit.
     *
     * @param Environment $environment
     * @param Commit $startCommit
     * @param int $limit
     *
     * @return Commit[]
     */
    private function loadCommitList(Environment $environment, Commit $startCommit, int $limit = 10): array
    {
        $commits = [$startCommit];
        if (!count($startCommit->parents) || $limit === 1) {
            return $commits;
        }

        $progress = new ProgressBar($this->stdErr->isDecorated() ? $this->stdErr : new NullOutput());
        $progress->setMessage('Loading...');
        $progress->setFormat('%message% %current% (limit: %max%)');
        $progress->start($limit);
        for ($currentCommit = $startCommit;
            count($currentCommit->parents) && count($commits) < $limit;) {
            foreach (array_reverse($currentCommit->parents) as $parentSha) {
                if (!isset($commits[$parentSha])) {
                    $commit = $this->gitDataApi->getCommit($environment, $parentSha);
                    if (!$commit) {
                        throw new \RuntimeException(sprintf('Commit not found: %s', $parentSha));
                    }
                    $commits[$parentSha] = $commit;
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
     * Summarizes a commit message.
     */
    private function summarize(string $message): string
    {
        $message = ltrim($message, "\n");
        if ($newLinePos = strpos($message, "\n")) {
            $message = substr($message, 0, $newLinePos);
        }

        return rtrim($message);
    }
}
