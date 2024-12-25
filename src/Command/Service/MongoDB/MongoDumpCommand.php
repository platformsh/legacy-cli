<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Service\MongoDB;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\Host\RemoteHost;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'service:mongo:dump', description: 'Create a binary archive dump of data from MongoDB', aliases: ['mongodump'])]
class MongoDumpCommand extends CommandBase
{
    public function __construct(private readonly Config $config, private readonly Git $git, private readonly QuestionHelper $questionHelper, private readonly Relationships $relationships, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('collection', 'c', InputOption::VALUE_REQUIRED, 'The collection to dump');
        $this->addOption('gzip', 'z', InputOption::VALUE_NONE, 'Compress the dump using gzip');
        $this->addOption('stdout', 'o', InputOption::VALUE_NONE, 'Output to STDOUT instead of a file');
        Relationships::configureInput($this->getDefinition());
        Ssh::configureInput($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->selector->addAppOption($this->getDefinition());
        $this->addCompleter($this->selector);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->selector->getProjectRoot();

        $gzip = $input->getOption('gzip');

        $envPrefix = $this->config->getStr('service.env_prefix');
        $selection = $this->selector->getSelection($input, new SelectorConfig(
            allowLocalHost: getenv($envPrefix . 'RELATIONSHIPS') !== false
                && getenv($envPrefix . 'APPLICATION_NAME') !== false,
        ));
        $host = $this->selector->getHostFromSelection($input, $selection);
        if ($host instanceof RemoteHost) {
            $appName = $selection->getAppName();
        } else {
            $appName = (string) getenv($envPrefix . 'APPLICATION_NAME');
        }

        $dumpFile = false;

        if (!$input->getOption('stdout')) {
            $defaultFilename = $this->getDefaultFilename($selection->hasEnvironment() ? $selection->getEnvironment() : null, $appName, $input->getOption('collection'), $gzip);
            $dumpFile = $projectRoot ? $projectRoot . '/' . $defaultFilename : $defaultFilename;
        }

        if ($dumpFile) {
            if (file_exists($dumpFile)) {
                if (!$this->questionHelper->confirm("File exists: <comment>$dumpFile</comment>. Overwrite?")) {
                    return 1;
                }
            }
            $this->stdErr->writeln(sprintf(
                'Creating %s file: <info>%s</info>',
                $gzip ? 'gzipped BSON archive' : 'BSON archive',
                $dumpFile,
            ));
        }
        $service = $this->relationships->chooseService($host, $input, $output, ['mongodb']);
        if (!$service) {
            return 1;
        }

        $command = 'mongodump ' . $this->relationships->getDbCommandArgs('mongodump', $service);

        if ($input->getOption('collection')) {
            $command .= ' --collection ' . OsUtil::escapePosixShellArg($input->getOption('collection'));
        }

        $command .= ' --archive';

        if ($output->isDebug()) {
            $command .= ' --verbose';
        }

        set_time_limit(0);

        if ($gzip) {
            $command .= ' --gzip';
        } elseif ($host instanceof RemoteHost) {
            // If dump compression is not enabled, data can still be compressed
            // transparently as it's streamed over the SSH connection.
            $host->setExtraSshOptions(['Compression yes']);
        }

        $append = '';
        if ($dumpFile) {
            $append = ' > ' . escapeshellarg($dumpFile);
        }

        $start = microtime(true);
        $exitCode = $host->runCommandDirect($command, $append);

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
        if ($dumpFile && file_exists($dumpFile) && $projectRoot && str_starts_with($dumpFile, $projectRoot)) {
            $git = $this->git;
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
     * @param Environment|null $environment
     * @param string|null      $appName
     * @param ?string           $collection
     * @param bool             $gzip
     *
     * @return string
     */
    private function getDefaultFilename(
        ?Environment $environment = null,
        ?string $appName = null,
        ?string $collection = '',
        bool $gzip = false,
    ): string {
        $prefix = $this->config->getStr('service.env_prefix');
        $projectId = $environment ? $environment->project : getenv($prefix . 'PROJECT');
        $environmentId = $environment ? $environment->id : getenv($prefix . 'BRANCH');
        $defaultFilename = $projectId ?: 'db';
        if ($environmentId) {
            $defaultFilename .= '--' . $environmentId;
        }
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
