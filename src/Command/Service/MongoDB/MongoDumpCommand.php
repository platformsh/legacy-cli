<?php

namespace Platformsh\Cli\Command\Service\MongoDB;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MongoDumpCommand extends CommandBase
{
    protected $hiddenInList = true;

    protected function configure()
    {
        $this->setName('service:mongo:dump');
        $this->setAliases(['mongodump']);
        $this->setDescription('Create a binary archive dump of data from MongoDB');
        $this->addOption('collection', 'c', InputOption::VALUE_REQUIRED, 'The collection to dump');
        $this->addOption('gzip', 'z', InputOption::VALUE_NONE, 'Compress the dump using gzip');
        $this->addOption('stdout', 'o', InputOption::VALUE_NONE, 'Output to STDOUT instead of a file');
        Relationships::configureInput($this->getDefinition());
        Ssh::configureInput($this->getDefinition());
        $this->addProjectOption()
            ->addEnvironmentOption()
            ->addAppOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $projectRoot = $this->getProjectRoot();

        $gzip = $input->getOption('gzip');

        $appName = $this->selectApp($input);
        $sshUrl = $this->getSelectedEnvironment()
            ->getSshUrl($appName);

        $dumpFile = false;

        if (!$input->getOption('stdout')) {
            $defaultFilename = $this->getDefaultFilename($this->getSelectedEnvironment(), $appName, $input->getOption('collection'), $gzip);
            $dumpFile = $projectRoot ? $projectRoot . '/' . $defaultFilename : $defaultFilename;
        }

        if ($dumpFile) {
            if (file_exists($dumpFile)) {
                /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
                $questionHelper = $this->getService('question_helper');
                if (!$questionHelper->confirm("File exists: <comment>$dumpFile</comment>. Overwrite?", false)) {
                    return 1;
                }
            }
            $this->stdErr->writeln(sprintf(
                'Creating %s file: <info>%s</info>',
                $gzip ? 'gzipped BSON archive' : 'BSON archive',
                $dumpFile
            ));
        }

        /** @var \Platformsh\Cli\Service\Relationships $relationshipsService */
        $relationshipsService = $this->getService('relationships');
        $service = $relationshipsService->chooseService($sshUrl, $input, $output, ['mongodb']);
        if (!$service) {
            return 1;
        }

        $command = 'mongodump ' . $relationshipsService->getDbCommandArgs('mongodump', $service);

        if ($input->getOption('collection')) {
            $command .= ' --collection ' . escapeshellarg($input->getOption('collection'));
        }

        $command .= ' --archive';

        if ($output->isDebug()) {
            $command .= ' --verbose';
        }

        set_time_limit(0);

        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');
        $sshCommand = $ssh->getSshCommand();
        $sshCommand .= ' ' . escapeshellarg($sshUrl);

        if ($gzip) {
            $command .= ' --gzip';
        } else {
            // If dump compression is not enabled, data can still be compressed
            // transparently as it's streamed over the SSH connection.
            $sshCommand .= ' -C';
        }

        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');

        $sshCommand .= ' ' . escapeshellarg($command);

        if ($dumpFile) {
            $sshCommand .= ' > ' . escapeshellarg($dumpFile);
        }

        $start = microtime(true);
        $exitCode = $shell->executeSimple($sshCommand);

        if ($exitCode === 0 && $output->isVerbose()) {
            $this->stdErr->writeln("\n" . 'The dump completed successfully');
            $this->stdErr->writeln(sprintf('  Time: %ss', number_format(microtime(true) - $start, 2)));
            if ($dumpFile && ($size = filesize($dumpFile)) !== false) {
                $this->stdErr->writeln(sprintf('  Size: %s', Helper::formatMemory($size)));
            }
            $this->stdErr->writeln('');
        }

        // If a dump file exists, check that it's excluded in the project's
        // .gitignore configuration.
        if ($dumpFile && file_exists($dumpFile) && $projectRoot && strpos($dumpFile, $projectRoot) === 0) {
            /** @var \Platformsh\Cli\Service\Git $git */
            $git = $this->getService('git');
            if (!$git->checkIgnore($dumpFile, $projectRoot)) {
                $this->stdErr->writeln('<comment>Warning: the dump file is not excluded by Git</comment>');
                if ($pos = strrpos($dumpFile, '.bson')) {
                    $extension = substr($dumpFile, $pos);
                    $this->stdErr->writeln('  You should probably exclude these files using .gitignore:');
                    $this->stdErr->writeln('    *' . $extension);
                }
            }
        }

        return $exitCode;
    }

    /**
     * Get the default dump filename.
     *
     * @param Environment $environment
     * @param string|null $appName
     * @param string      $collection
     * @param bool        $gzip
     *
     * @return string
     */
    private function getDefaultFilename(
        Environment $environment,
        $appName = null,
        $collection = '',
        $gzip = false)
    {
        $defaultFilename = $environment->project . '--' . $environment->id;
        if ($appName !== null) {
            $defaultFilename .= '--' . $appName;
        }
        if ($collection) {
            $defaultFilename .= '--' . $collection;
        }
        $defaultFilename .= '--archive.bson';
        if ($gzip) {
            $defaultFilename .= '.gz';
        }

        return $defaultFilename;
    }
}
