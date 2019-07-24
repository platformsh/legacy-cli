<?php
namespace Platformsh\Cli\Command\Archive;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\RemoteContainer\App;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Platformsh\Client\Model\ProjectLevelVariable;
use Platformsh\Client\Model\Variable;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\PhpExecutableFinder;

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
            ->addArgument('file', InputArgument::REQUIRED, 'The archive filename')
            ->addOption('include-variables', null, InputOption::VALUE_NONE, 'Import environment-level variables')
            ->addOption('include-project-variables', null, InputOption::VALUE_NONE, 'Import project-level variables');
        $this->addProjectOption();
        $this->addEnvironmentOption();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $filename = (string) $input->getArgument('file');
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

        $environment = $this->getSelectedEnvironment();

        $this->stdErr->writeln(sprintf(
            'Importing into environment <info>%s</info> on the project <info>%s</info>',
            $this->api()->getEnvironmentLabel($environment),
            $this->api()->getProjectLabel($this->getSelectedProject())
        ));

        $this->stdErr->writeln('');
        $this->stdErr->writeln('<options=bold;fg=yellow>Warning:</>');
        $this->stdErr->writeln(sprintf('Any data on %s may be deleted. This action cannot be undone.', $this->api()->getEnvironmentLabel($environment, 'comment')));
        $this->stdErr->writeln('');
        $this->stdErr->writeln('<options=bold;fg=yellow>also please note:</>');
        $this->stdErr->writeln('Currently, data is imported, but no existing data is deleted (so you may see inconsistent results).');
        $this->stdErr->writeln('');

        if (!$questionHelper->confirm('Are you sure you want to continue?', false)) {
            return 1;
        }

        $tmpDir = $fs->makeTempDir('archive-');
        $fs->extractArchive($filename, $tmpDir);

        $this->debug('Extracted archive to: ' . $tmpDir);

        foreach ((array) scandir($tmpDir) as $filename) {
            if (!empty($filename) && $filename[0] !== '.' && is_dir($tmpDir . '/' . $filename)) {
                $archiveId = $filename;
                break;
            }
        }
        if (empty($archiveId)) {
            $this->stdErr->writeln('<error>Error:</error> Failed to identify archive subdirectory');

            return 1;
        }
        $archiveDir = $tmpDir . '/' . $archiveId;

        $metadata = file_get_contents($archiveDir . '/archive.json');
        if ($metadata === false || !($metadata = json_decode($metadata, true))) {
            $this->stdErr->writeln('<error>Error:</error> Failed to read archive metadata');

            return 1;
        }

        if ($metadata['version'] < ArchiveExportCommand::ARCHIVE_VERSION) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('<error>Error:</error> The archive is outdated so it cannot be imported.');
            $this->stdErr->writeln(sprintf('  Archive version: <error>%s</error> (from CLI version: %s)', $metadata['version'], $metadata['cli_version']));
            $this->stdErr->writeln(sprintf('  Current version: %s (CLI version: %s)', ArchiveExportCommand::ARCHIVE_VERSION, $this->config()->getVersion()));

            return 1;
        }

        $activities = [];

        if (!empty($metadata['variables']['environment'])
            && ($input->getOption('include-variables') || ($input->isInteractive() && $questionHelper->confirm("\nImport environment-level variables?", false)))) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('Importing environment-level variables');

            foreach ($metadata['variables']['environment'] as $name => $var) {
                $this->stdErr->writeln('  Processing variable <info>' . $name . '</info>');
                if (!array_key_exists('value', $var)) {
                    if ($var['is_sensitive']) {
                        $this->stdErr->writeln('  Skipping sensitive variable <comment>' . $name . '</comment>');
                        continue;
                    }
                    $this->stdErr->writeln('    Error: no variable value found.');
                    continue;
                }
                if ($current = $environment->getVariable($name)) {
                    $this->stdErr->writeln('    The variable already exists.');
                    if ($this->variablesAreEqual($current->getProperties(), $var)) {
                        $this->stdErr->writeln('    No change required.');
                        continue;
                    }

                    if ($questionHelper->confirm('    Do you want to update it?')) {
                        $result = $current->update($var);
                    } else {
                        continue;
                    }
                } else {
                    $result = $this->createEnvironmentVariableFromProperties($var, $environment);

                }
                $this->stdErr->writeln('    Done');
                $activities = array_merge($activities, $result->getActivities());
            }
        }

        if (!empty($metadata['variables']['project'])
            && ($input->getOption('include-project-variables') || ($input->isInteractive() && $questionHelper->confirm("\nImport project-level variables?", false)))) {
            $project = $this->getSelectedProject();

            $this->stdErr->writeln('');
            $this->stdErr->writeln('Importing project-level variables');

            foreach ($metadata['variables']['project'] as $name => $var) {
                $this->stdErr->writeln('  Processing variable <info>' . $name . '</info>');
                if (!array_key_exists('value', $var)) {
                    if ($var['is_sensitive']) {
                        $this->stdErr->writeln('  Skipping sensitive variable <comment>' . $name . '</comment>');
                        continue;
                    }
                    $this->stdErr->writeln('    Error: no variable value found.');
                    continue;
                }
                if ($current = $project->getVariable($name)) {
                    $this->stdErr->writeln('    The variable already exists.');
                    if ($this->variablesAreEqual($current->getProperties(), $var)) {
                        $this->stdErr->writeln('    No change required.');
                        continue;
                    }

                    if (!$questionHelper->confirm('    Do you want to update it?')) {
                        return 1;
                    }

                    $result = $current->update($var);
                } else {
                    $result = $this->createProjectVariableFromProperties($var, $project);
                }
                $this->stdErr->writeln('    Done');
                $activities = array_merge($activities, $result->getActivities());
            }
        }

        $success = true;

        if (!empty($metadata['services'])) {
            foreach ($metadata['services'] as $serviceName => $serviceInfo) {
                if (in_array($serviceInfo['_type'], ['mysql', 'pgsql'])) {
                    $this->stdErr->writeln('');
                    $this->stdErr->writeln('Importing data for service <info>' . $serviceName . '</info>');

                    foreach ($serviceInfo['dumps'] as $dumpInfo) {
                        if (!empty($dumpInfo['schema'])) {
                            $this->stdErr->writeln('Processing schema: <info>' . $dumpInfo['schema'] . '</info>');
                        }
                        $this->importDatabaseDump($archiveDir, $dumpInfo);
                    }
                } elseif ($serviceInfo['_type'] === 'mongodb') {
                    $this->stdErr->writeln('');
                    $this->stdErr->writeln('Importing data for service <info>' . $serviceName . '</info>');

                    foreach ($serviceInfo['dumps'] as $dumpInfo) {
                        $this->importMongoDump($archiveDir, $dumpInfo);
                    }
                }
            }
        }

        if (!empty($metadata['mounts'])) {
            /** @var \Platformsh\Cli\Service\Rsync $rsync */
            $rsync = $this->getService('rsync');
            $rsyncOptions = [
                'verbose' => $output->isVeryVerbose(),
                'quiet' => !$output->isVerbose(),
            ];
            $deployment = $environment->getCurrentDeployment();
            foreach ($metadata['mounts'] as $path => $info) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('Importing files to mount <info>' . $path . '</info>');
                $app = new App($deployment->getWebApp($info['app']), $environment);
                $rsync->syncUp($app->getSshUrl(), $archiveDir . '/' . $info['path'], $path, $rsyncOptions);
            }
        }

        if (!empty($activities) && $this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitMultiple($activities, $this->getSelectedProject());
        }

        return $success ? 0 : 1;
    }

    /**
     * @param array                                $properties
     * @param \Platformsh\Client\Model\Environment $environment
     *
     * @return \Platformsh\Client\Model\Result
     */
    private function createEnvironmentVariableFromProperties(array $properties, Environment $environment)
    {
        $var = $properties;
        unset($var['project'], $var['environment'], $var['created_at'], $var['updated_at'], $var['id'], $var['attributes'], $var['inherited']);

        return Variable::create($var, $environment->getLink('#manage-variables'), $this->api()->getHttpClient());
    }

    /**
     * @param array                            $properties
     * @param \Platformsh\Client\Model\Project $project
     *
     * @return \Platformsh\Client\Model\Result
     */
    private function createProjectVariableFromProperties(array $properties, Project $project)
    {
        $var = $properties;
        unset($var['project'], $var['environment'], $var['created_at'], $var['updated_at'], $var['id'], $var['attributes']);

        return ProjectLevelVariable::create($var, $project->getLink('#manage-variables'), $this->api()->getHttpClient());
    }


    /**
     * Checks if two variables are equal.
     * 
     * @param array $var1Properties
     * @param array $var2Properties
     *
     * @return bool
     */
    private function variablesAreEqual(array $var1Properties, $var2Properties)
    {
        $keys = [
            'value',
            'is_json',
            'is_enabled',
            'is_sensitive',
            'is_inheritable',
            'visible_build',
            'visible_runtime',
        ];
        foreach ($keys as $key) {
            if (array_key_exists($key, $var1Properties)
                && (!array_key_exists($key, $var2Properties) || $var1Properties[$key] !== $var2Properties[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param string $commandName
     * @param array  $args
     * @param string $suffix
     */
    private function runCliCommandViaShell($commandName, array $args = [], $suffix = '')
    {
        $args = array_merge([
            (new PhpExecutableFinder())->find(false) ?: 'php',
            $GLOBALS['argv'][0],
            $commandName,
        ], $args);
        $command = implode(' ', array_map('escapeshellarg', $args)) . $suffix;

        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');
        $exitCode = $shell->executeSimple($command);
        if ($exitCode !== 0) {
            throw new \RuntimeException("Command failed with exit code $exitCode:" . $command);
        }
    }

    /**
     * @param string $archiveDir
     * @param array  $dumpInfo
     */
    private function importDatabaseDump($archiveDir, array $dumpInfo)
    {
        if (!file_exists($archiveDir . '/' . $dumpInfo['filename'])) {
            throw new \RuntimeException('Dump file not found: ' . $archiveDir . '/' . $dumpInfo['filename']);
        }
        $args = [
            '--project=' . $this->getSelectedProject()->id,
            '--environment=' . $this->getSelectedEnvironment()->id,
            '--app=' . $dumpInfo['app'],
            '--relationship=' . $dumpInfo['relationship'],
            '--yes',
        ];
        if (!empty($dumpInfo['schema'])) {
            $args[] = '--schema=' . $dumpInfo['schema'];
        }
        if ($this->stdErr->isVerbose()) {
            $args[] = '--verbose';
        }
        $this->runCliCommandViaShell('db:sql', $args, ' < ' . escapeshellarg($archiveDir . '/' . $dumpInfo['filename']));
    }

    /**
     * @param string $archiveDir
     * @param array  $dumpInfo
     */
    private function importMongoDump($archiveDir, array $dumpInfo)
    {
        if (!file_exists($archiveDir . '/' . $dumpInfo['filename'])) {
            throw new \RuntimeException('Dump file not found: ' . $archiveDir . '/' . $dumpInfo['filename']);
        }
        $args = [
            '--project=' . $this->getSelectedProject()->id,
            '--environment=' . $this->getSelectedEnvironment()->id,
            '--app=' . $dumpInfo['app'],
            '--relationship=' . $dumpInfo['relationship'],
            '--yes',
        ];
        if ($this->stdErr->isVerbose()) {
            $args[] = '--verbose';
        }
        $this->runCliCommandViaShell('service:mongo:restore', $args, ' < ' . escapeshellarg($archiveDir . '/' . $dumpInfo['filename']));
    }
}
