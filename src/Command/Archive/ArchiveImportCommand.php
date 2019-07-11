<?php
namespace Platformsh\Cli\Command\Archive;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ArchiveImportCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('archive:import')
            ->setDescription('Import an archive')
            ->addArgument('file', InputArgument::REQUIRED, 'The archive filename');
        $this->addProjectOption();
        $this->addEnvironmentOption();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $filename = $input->getArgument('file');
        if (!file_exists($filename)) {
            $this->stdErr->writeln(sprintf('File not found: <error>%s</error>', $filename));

            return 1;
        }
        if (!is_readable($filename)) {
            $this->stdErr->writeln(sprintf('Not readable: <error>%s</error>', $filename));

            return 1;
        }
        if (substr($filename, -7) !== '.tar.gz') {
            $this->stdErr->writeln(sprintf('Unexpected format: <error>%s</error> (expected: .tar.gz)', $filename));

            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        /** @var \Platformsh\Cli\Service\Filesystem $fs */
        $fs = $this->getService('fs');

        $this->stdErr->writeln(sprintf(
            'Importing archive into environment <info>%s</info> on the project <info>%s</info>',
            $this->api()->getEnvironmentLabel($this->getSelectedEnvironment()),
            $this->api()->getProjectLabel($this->getSelectedProject())
        ));
        $this->stdErr->writeln('');

        if (!$questionHelper->confirm('Are you sure you want to continue?', false)) {
            return 1;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'archive-');
        unlink($tmpFile);
        $tmpDir = $tmpFile;
        unset($tmpFile);
        if (!mkdir($tmpDir)) {
            $this->stdErr->writeln(sprintf('Failed to create temporary directory: <error>%s</error>', $tmpDir));

            return 1;
        }
        $fs->extractArchive($filename, $tmpDir);

        $this->stdErr->writeln('Extracted archive to: ' . $tmpDir);

        return 0;
    }
}
