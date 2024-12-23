<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Commit;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\GitDataApi;
use Platformsh\Cli\Service\PropertyFormatter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'commit:get', description: 'Show commit details')]
class CommitGetCommand extends CommandBase
{
    public function __construct(private readonly GitDataApi $gitDataApi, private readonly Io $io, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('commit', InputArgument::OPTIONAL, 'The commit SHA. ' . GitDataApi::COMMIT_SYNTAX_HELP, 'HEAD')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The commit property to display.');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);

        $definition = $this->getDefinition();
        PropertyFormatter::configureInput($definition);

        // Deprecated options, left for backwards compatibility
        $this->addHiddenOption('format', null, InputOption::VALUE_REQUIRED, 'DEPRECATED');
        $this->addHiddenOption('columns', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'DEPRECATED');
        $this->addHiddenOption('no-header', null, InputOption::VALUE_NONE, 'DEPRECATED');

        $this->addExample('Display the current commit on the environment');
        $this->addExample('Display the previous commit', 'HEAD~');
        $this->addExample('Display the 3rd commit before the current one', 'HEAD~3');
        $this->addExample('Display the email address of the last commit author', '-P author.email');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io->warnAboutDeprecatedOptions(['columns', 'format', 'no-header']);
        $selection = $this->selector->getSelection($input, new SelectorConfig(selectDefaultEnv: true));

        $commitSha = $input->getArgument('commit');
        $commit = $this->gitDataApi->getCommit($selection->getEnvironment(), $commitSha);
        if (!$commit) {
            if ($commitSha) {
                $this->stdErr->writeln('Commit not found: <error>' . $commitSha . '</error>');
            } else {
                $this->stdErr->writeln('Commit not found.');
            }

            return 1;
        }
        $this->propertyFormatter->displayData($output, $commit->getProperties(), $input->getOption('property'));

        return 0;
    }
}
