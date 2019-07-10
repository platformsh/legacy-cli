<?php
namespace Platformsh\Cli\Command\Archive;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\RemoteContainer\App;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ArchiveExportCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('archive:export')
            ->setDescription('Export an archive from an app')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'The filename for the archive')
            ->addOption('exclude-service', 'P', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exclude a service');
        $this->addProjectOption();
        $this->addEnvironmentOption();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        /** @var \Platformsh\Cli\Service\Filesystem $fs */
        $fs = $this->getService('fs');

        $this->stdErr->writeln(sprintf(
            'Archiving data from the project <info>%s</info>, environment <info>%s</info>',
            $this->api()->getProjectLabel($this->getSelectedProject()),
            $this->api()->getEnvironmentLabel($this->getSelectedEnvironment())
        ));
        $this->stdErr->writeln('');

        $environment = $this->getSelectedEnvironment();
        $deployment = $this->api()->getCurrentDeployment($environment, true);

        $filename = $input->getOption('file');
        if ($filename === null) {
            $filename = sprintf('archive-%s-%s.tar.gz', $environment->machine_name, $environment->project);
            $filename = strtolower($filename);
        }
        if (file_exists($filename)) {
            $this->stdErr->writeln(sprintf('The file already exists: <comment>%s</comment>', $filename));
            if (!$questionHelper->confirm('Overwrite?')) {
                return 1;
            }
        }
        if (!$fs->canWrite($filename)) {
            $this->stdErr->writeln('File not writable: <error>' . $filename . '</error>');

            return 1;
        }

        $serviceSupport = [
            'mysql' => 'using "db:dump"',
            'postgresql' => 'using "db:dump"',
            'mongodb' => 'using "mongodump"',
            'network-storage' => 'via mounts',
        ];
        $supportedServices = [];
        $unsupportedServices = [];
        $ignoredServices = [];
        foreach ($deployment->services as $name => $service) {
            list($type, ) = explode(':', $service->type, 2);
            if (isset($serviceSupport[$type]) && !empty($service->disk)) {
                $supportedServices[$name] = $type;
            } elseif (empty($service->disk)) {
                $ignoredServices[$name] = $type;
            } else {
                $unsupportedServices[$name] = $type;
            }
        }

        if (!empty($supportedServices)) {
            $this->stdErr->writeln('Supported services:');
            foreach ($supportedServices as $name => $type) {
                $this->stdErr->writeln(sprintf(
                    '  - <info>%s</info> (%s), %s',
                    $name,
                    $type,
                    $serviceSupport[$type]
                ));
            }
            $this->stdErr->writeln('');
        }

        if (!empty($ignoredServices)) {
            $this->stdErr->writeln('Ignored services, without disk storage:');
            foreach ($ignoredServices as $name => $type) {
                $this->stdErr->writeln(
                    sprintf('  - %s (%s)', $name, $type)
                );
            }
            $this->stdErr->writeln('');
        }

        if (!empty($unsupportedServices)) {
            $this->stdErr->writeln('Unsupported services:');
            foreach ($unsupportedServices as $name => $type) {
                $this->stdErr->writeln(
                    sprintf('  - <error>%s</error> (%s)', $name, $type)
                );
            }
            $this->stdErr->writeln('');
        }

        $apps = [];
        $hasMounts = false;
        foreach ($deployment->webapps as $name => $webApp) {
            $app = new App($webApp, $environment);
            $apps[$name] = $app;
            $hasMounts = $hasMounts || count($app->getMounts());
        }

        if ($hasMounts && count($supportedServices)) {
            $this->stdErr->writeln('Exports from the supported service(s) and files from mounts will be downloaded locally.');
        } elseif (count($supportedServices)) {
            $this->stdErr->writeln('Exports from the above service(s) will be downloaded locally.');
        } elseif ($hasMounts) {
            $this->stdErr->writeln('Files from mounts will be downloaded locally.');
        } else {
            $this->stdErr->writeln('No supported services or mounts were found.');
            return 1;
        }
        $this->stdErr->writeln('');

        if (!$questionHelper->confirm('Are you sure you want to continue?')) {
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
        $archiveDir = $tmpDir . '/' . 'archive-' . $environment->machine_name . '-' . $environment->project;
        if (!mkdir($archiveDir)) {
            $this->stdErr->writeln(sprintf('Failed to create archive directory: <error>%s</error>', $archiveDir));

            return 1;
        }
        $this->debug('Using archive directory: ' . $archiveDir);

        file_put_contents($archiveDir . '/archive.json', json_encode([
            'time' => date('c'),
            'project' => $this->getSelectedProject()->getData(),
            'environment' => $environment->getData(),
            'deployment' => $deployment->getData(),
        ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

        if ($hasMounts) {
            foreach ($apps as $app) {
                $sourcePaths = [];
                $mounts = $app->getMounts();
                foreach ($mounts as $path => $mount) {
                    if ($mount['source'] === 'local' && isset($mount['source_path'])) {
                        if (isset($sourcePaths[$mount['source_path']])) {
                            continue;
                        }
                        $sourcePaths[$mount['source_path']] = true;
                    }
                    $this->stdErr->writeln('Copying from mount <info>' . $path . '</info>');
                    $source = ltrim($path, '/');
                    $destination = $archiveDir . '/mounts/' . ltrim($path, '/');
                    mkdir($destination, 0755, true);
                    $this->rsync($app->getSshUrl(), $source, $destination);
                }
            }
        }

        $fs->archiveDir($tmpDir, $filename);
        $fs->remove($tmpDir);

        $this->stdErr->writeln('Archive: <info>' . $filename . '</info>');

        return 0;
    }

    /**
     * Rsync from a remote path to a local one.
     *
     * @param string $sshUrl
     * @param string $sshPath
     * @param string $localPath
     * @param array  $options
     */
    private function rsync($sshUrl, $sshPath, $localPath, array $options = [])
    {
        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');

        $params = ['rsync', '--archive', '--compress', '--human-readable'];

        if ($this->stdErr->isVeryVerbose()) {
            $params[] = '-vv';
        } elseif ($this->stdErr->isVerbose()) {
            $params[] = '-v';
        }

        $params[] = sprintf('%s:%s/', $sshUrl, $sshPath);
        $params[] = $localPath;

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
