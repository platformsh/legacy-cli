<?php
namespace Platformsh\Cli\Command\Archive;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\RemoteContainer\App;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

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

        $archiveFilename = $input->getOption('file');
        if ($archiveFilename === null) {
            $archiveFilename = sprintf('archive-%s--%s.tar.gz', $environment->machine_name, $environment->project);
            $archiveFilename = strtolower($archiveFilename);
        }
        if (file_exists($archiveFilename)) {
            $this->stdErr->writeln(sprintf('The file already exists: <comment>%s</comment>', $archiveFilename));
            if (!$questionHelper->confirm('Overwrite?')) {
                return 1;
            }
        }
        if (!$fs->canWrite($archiveFilename)) {
            $this->stdErr->writeln('File not writable: <error>' . $archiveFilename . '</error>');

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
        $archiveDir = $tmpDir . '/' . 'archive-' . $environment->machine_name . '--' . $environment->project;
        if (!mkdir($archiveDir)) {
            $this->stdErr->writeln(sprintf('Failed to create archive directory: <error>%s</error>', $archiveDir));
            // @todo refactor this cleanup
            $fs->remove($tmpDir);

            return 1;
        }
        $this->debug('Using archive directory: ' . $archiveDir);

        $metadata = [
            'time' => date('c'),
            'project' => $this->getSelectedProject()->getData(),
            'environment' => $environment->getData(),
            'deployment' => $deployment->getData(),
        ];

        foreach ($supportedServices as $serviceName => $type) {
            $this->stdErr->writeln('Archiving service <info>' . $serviceName . '</info>');

            // Find a relationship to the service.
            $relationshipName = false;
            $appName = false;
            foreach ($apps as $app) {
                $relationshipName = $this->getRelationshipNameForService($app, $serviceName);
                if ($relationshipName !== false) {
                    $appName = $app->getName();
                    break;
                }
            }
            if ($relationshipName === false || $appName === false) {
                $this->stdErr->writeln('No app defines a relationship to the service <error>%s</error> (<error>%s</error>)');
                // @todo refactor this cleanup
                $fs->remove($tmpDir);

                return 1;
            }
            if ($type === 'mysql' || $type === 'pgsql') {
                // Get a list of schemas from the service configuration.
                $service = $deployment->getService($serviceName);
                $schemas = !empty($service->configuration['schemas'])
                    ? $service->configuration['schemas']
                    : [];

                // Filter the list by the schemas accessible from the endpoint.
                if (isset($database['rel'])
                    && isset($service->configuration['endpoints'][$relationshipName]['privileges'])) {
                    $schemas = array_intersect(
                        $schemas,
                        array_keys($service->configuration['endpoints'][$relationshipName]['privileges'])
                    );
                }

                mkdir($archiveDir . '/services/' . $serviceName, 0755, true);

                if (count($schemas) <= 1) {
                    $schemas = [null];
                }

                foreach ($schemas as $schema) {
                    $filename = $appName . '--' . $relationshipName;
                    $args = [
                        '--directory' => $archiveDir . '/services/' . $serviceName,
                        '--project' => $this->getSelectedProject()->id,
                        '--environment' => $this->getSelectedEnvironment()->id,
                        '--app' => $appName,
                        '--relationship' => $relationshipName,
                        '--yes' => true,
                        // gzip is not enabled because the archive will be gzipped anyway
                    ];
                    if ($schema !== null) {
                        $args['--schema'] = $schema;
                        $filename .= '--' . $schema;
                    }
                    $filename .= '.sql';
                    $args['--file'] = $filename;
                    $exitCode = $this->runOtherCommand('db:dump', $args);
                    if ($exitCode !== 0) {
                        // @todo refactor this cleanup
                        $fs->remove($tmpDir);

                        return $exitCode;
                    }
                    if ($schema !== null) {
                        $metadata['services'][$serviceName][$schema] = 'services/' . $serviceName . '/' . $filename;
                    } else {
                        $metadata['services'][$serviceName] = 'services/' . $serviceName . '/' . $filename;
                    }
                }
            }
            if ($type === 'mongodb') {
                $args = [
                    '--directory' => $archiveDir . '/services/' . $serviceName,
                    '--project' => $this->getSelectedProject()->id,
                    '--environment' => $this->getSelectedEnvironment()->id,
                    '--app' => $appName,
                    '--relationship' => $relationshipName,
                    '--yes' => true,
                    '--stdout' => true,
                    // gzip is not enabled because the archive will be gzipped anyway
                ];
                $filename = $appName . '--' . $relationshipName . '.bson';
                // @todo dump directly to a file without the buffer
                $buffer = new BufferedOutput();
                $exitCode = $this->runOtherCommand('db:dump', $args, $buffer);
                if ($exitCode !== 0) {
                    // @todo refactor this cleanup
                    $fs->remove($tmpDir);

                    return $exitCode;
                }
                (new Filesystem())->dumpFile($filename, $buffer->fetch());
                $metadata['services'][$serviceName] = 'services/' . $serviceName . '/' . $filename;
            }
        }

        if ($hasMounts) {
            /** @var \Platformsh\Cli\Service\Mount $mountService */
            $mountService = $this->getService('mount');
            foreach ($apps as $app) {
                $sourcePaths = [];
                $mounts = $mountService->normalizeMounts($app->getMounts());
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
                    $metadata['mounts'][$path] = 'mounts/' . ltrim($path, '/');
                }
            }
        }

        $this->stdErr->writeln('Writing metadata');
        (new Filesystem())->dumpFile($archiveDir . '/archive.json', json_encode($metadata, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

        $this->stdErr->writeln('Compressing archive');
        $fs->archiveDir($tmpDir, $archiveFilename);

        // @todo also clean up on error
        $this->stdErr->writeln('Cleaning up');
        $fs->remove($tmpDir);

        $this->stdErr->writeln('');
        $this->stdErr->writeln('Archive: <info>' . $archiveFilename . '</info>');

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

    /**
     * @param \Platformsh\Cli\Model\RemoteContainer\App $app
     * @param string                                    $serviceName
     *
     * @return string|false
     */
    private function getRelationshipNameForService(App $app, $serviceName)
    {
        $config = $app->getConfig()->getNormalized();
        $relationships = isset($config['relationships']) ? $config['relationships'] : [];
        foreach ($relationships as $relationshipName => $relationshipTarget) {
            list($targetService,) = explode(':', $relationshipTarget);
            if ($targetService === $serviceName) {
                return $relationshipName;
            }
        }

        return false;
    }
}
