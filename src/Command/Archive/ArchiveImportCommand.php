<?php
namespace Platformsh\Cli\Command\Archive;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\RemoteContainer\App;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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

        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');

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

        $tmpFile = tempnam(sys_get_temp_dir(), 'archive-');
        unlink($tmpFile);
        $tmpDir = $tmpFile;
        unset($tmpFile);
        if (!mkdir($tmpDir)) {
            $this->stdErr->writeln(sprintf('Failed to create temporary directory: <error>%s</error>', $tmpDir));

            return 1;
        }

        register_shutdown_function(function () use($tmpDir, $fs) {
            if (file_exists($tmpDir)) {
                $this->stdErr->writeln("\nCleaning up", OutputInterface::VERBOSITY_VERBOSE);
                $fs->remove($tmpDir);
            }
        });

        $fs->extractArchive($filename, $tmpDir);

        $this->debug('Extracted archive to: ' . $tmpDir);

        $archiveDir = $tmpDir . '/archive';

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

        if (!empty($metadata['variables']['environment'])) {
            // @todo project-level variables could be problematic...
            $this->stdErr->writeln('');
            $this->stdErr->writeln('Importing environment-level variables');

            foreach ($metadata['variables']['environment'] as $name => $var) {
                if ($var['is_sensitive']) {
                    $this->stdErr->writeln('  Skipping sensitive variable <comment>' . $name . '</comment>');
                    continue;
                }
                $this->stdErr->writeln('  ' . $name);
                if (!array_key_exists('value', $var)) {
                    $this->stdErr->writeln('    Error: no variable value found.');
                    continue;
                }
                $result = $environment->setVariable($name, $var['value'], $var['is_json'], $var['is_enabled'], $var['is_sensitive']);
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
                        if (empty($dumpInfo['filename']) || !file_exists($archiveDir . '/' . $dumpInfo['filename'])) {
                            $this->stdErr->writeln('Dump file not found: ' . $archiveDir . '/' . $dumpInfo['filename']);
                            continue;
                        }
                        $args = [
                            $GLOBALS['argv'][0],
                            'db:sql',
                            '--project=' . $this->getSelectedProject()->id,
                            '--environment=' . $this->getSelectedEnvironment()->id,
                            '--app=' . $dumpInfo['app'],
                            '--relationship=' . $dumpInfo['relationship'],
                            '--yes',
                        ];
                        if (!empty($dumpInfo['schema'])) {
                            $args[] = '--schema=' . $dumpInfo['schema'];
                        }
                        if ($output->isVerbose()) {
                            $args[] = '--verbose';
                        }
                        $command = (new PhpExecutableFinder())->find(false) . ' ' . implode(' ', array_map('escapeshellarg', $args));
                        $command .= ' < ' . escapeshellarg($archiveDir . '/' . $dumpInfo['filename']);
                        $exitCode = $shell->executeSimple($command);
                        if ($exitCode !== 0) {
                            $success = false;
                        }
                    }
                } elseif ($serviceInfo['_type'] === 'mongodb') {
                    $this->stdErr->writeln('');
                    $this->stdErr->writeln('Importing data for service <info>' . $serviceName . '</info>');

                    if (empty($serviceInfo['filename']) || !file_exists($archiveDir . '/' . $serviceInfo['filename'])) {
                        $this->stdErr->writeln('Dump file not found: ' . $archiveDir . '/' . $serviceInfo['filename']);
                        continue;
                    }

                    $args = [
                        $GLOBALS['argv'][0],
                        'service:mongo:restore',
                        '--project=' . $this->getSelectedProject()->id,
                        '--environment=' . $this->getSelectedEnvironment()->id,
                        '--app=' . $serviceInfo['app'],
                        '--relationship=' . $serviceInfo['relationship'],
                        '--yes',
                    ];
                    if ($output->isVerbose()) {
                        $args[] = '--verbose';
                    }
                    $command = (new PhpExecutableFinder())->find(false) . ' ' . implode(' ', array_map('escapeshellarg', $args));
                    $command .= ' < ' . escapeshellarg($archiveDir . '/' . $serviceInfo['filename']);
                    $exitCode = $shell->executeSimple($command);
                    if ($exitCode !== 0) {
                        $success = false;
                    }
                }
            }
        }

        if (!empty($metadata['mounts'])) {
            $deployment = $environment->getCurrentDeployment();
            foreach ($metadata['mounts'] as $path => $info) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('Importing files to mount <info>' . $path . '</info>');
                $app = new App($deployment->getWebApp($info['app']), $environment);
                $this->rsyncUp($app->getSshUrl(), $path, $archiveDir . '/' . $info['path']);
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
     * Rsync from a local path to a remote one.
     *
     * @param string $sshUrl
     * @param string $sshPath
     * @param string $localPath
     * @param array  $options
     */
    private function rsyncUp($sshUrl, $sshPath, $localPath, array $options = [])
    {
        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');

        $params = ['rsync', '--archive', '--compress', '--human-readable'];

        if ($this->stdErr->isVeryVerbose()) {
            $params[] = '-vv';
        } elseif ($this->stdErr->isVerbose()) {
            $params[] = '-v';
        }

        $params[] = rtrim($localPath, '/') . '/';
        $params[] = sprintf('%s:%s', $sshUrl, $sshPath);

        if (!empty($options['delete'])) {
            $params[] = '--delete';
        }
        foreach (['exclude', 'include'] as $option) {
            if (!empty($options[$option])) {
                foreach ($options[$option] as $value) {
                    $params[] = '--' . $option . '=' . $value;
                }
            }
        }

        $start = microtime(true);
        $shell->execute($params, null, true, false, [], null);

        $this->stdErr->writeln(sprintf('  time: %ss', number_format(microtime(true) - $start, 2)), OutputInterface::VERBOSITY_NORMAL);
    }
}
