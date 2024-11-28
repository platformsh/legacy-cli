<?php

namespace Platformsh\Cli\Command\Commit;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\GitDataApi;
use Platformsh\Cli\Service\PropertyFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CommitGetCommand extends CommandBase
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('commit:get')
            ->setDescription('Show commit details')
            ->addArgument('commit', InputArgument::OPTIONAL, 'The commit SHA. ' . GitDataApi::COMMIT_SYNTAX_HELP, 'HEAD')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The commit property to display.');
        $this->addProjectOption();
        $this->addEnvironmentOption();

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
        $this->warnAboutDeprecatedOptions(['columns', 'format', 'no-header']);
        $this->validateInput($input, false, true);

        $commitSha = $input->getArgument('commit');
        /** @var \Platformsh\Cli\Service\GitDataApi $gitData */
        $gitData = $this->getService('git_data_api');
        $commit = $gitData->getCommit($this->getSelectedEnvironment(), $commitSha);
        if (!$commit) {
            if ($commitSha) {
                $this->stdErr->writeln('Commit not found: <error>' . $commitSha . '</error>');
            } else {
                $this->stdErr->writeln('Commit not found.');
            }

            return 1;
        }

        /** @var PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');
        $formatter->displayData($output, $commit->getProperties(), $input->getOption('property'));

        return 0;
    }
}
