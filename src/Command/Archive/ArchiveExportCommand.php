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
    // @todo refactor this
    const ARCHIVE_VERSION = 3; // increment this when BC-breaking changes are introduced

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('archive:export')
            ->setDescription('Export an archive from an environment')
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'The filename for the archive')
            ->addOption('exclude-services', null, InputOption::VALUE_NONE, 'Exclude services')
            ->addOption('exclude-mounts', null, InputOption::VALUE_NONE, 'Exclude mounts')
            ->addOption('include-variables', null, InputOption::VALUE_NONE, 'Include variables');
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

        $archiveId = sprintf('archive-%s--%s', $environment->machine_name, $environment->project);
        $archiveFilename = (string) $input->getOption('file');
        if ($archiveFilename === '') {
            $archiveFilename = $archiveId . '.tar.gz';
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

        $excludeServices = (bool) $input->getOption('exclude-services');
        $serviceSupport = [
            'mysql' => 'using "db:dump"',
            'postgresql' => 'using "db:dump"',
            'mongodb' => 'using "mongodump"',
            'network-storage' => 'via mounts',
        ];
        $supportedServices = [];
        $unsupportedServices = [];
        $ignoredServices = [];
        if (!$excludeServices) {
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
        $excludeMounts = (bool) $input->getOption('exclude-mounts');
        $includeVariables = (bool) $input->getOption('include-variables');
        foreach ($deployment->webapps as $name => $webApp) {
            $app = new App($webApp, $environment);
            $apps[$name] = $app;
            $hasMounts = !$excludeMounts && ($hasMounts || count($app->getMounts()));
        }

        $nothingToDo = true;

        if ($includeVariables) {
            $this->stdErr->writeln('Environment metadata (including variables) will be saved.');
            $nothingToDo = false;
        }

        if ($hasMounts && !$excludeMounts) {
            $this->stdErr->writeln('Files from mounts will be downloaded.');
            $nothingToDo = false;
        }

        if (!$excludeServices && !empty($supportedServices)) {
            $this->stdErr->writeln('Exports from the above supported service(s) will be saved.');
            $nothingToDo = false;
        }

        if ($nothingToDo) {
            $this->stdErr->writeln('There is nothing to export.');
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

        // Clean up the temporary directory when done.
        register_shutdown_function(function () use($tmpDir, $fs) {
            if (file_exists($tmpDir)) {
                $this->stdErr->writeln("\nCleaning up");
                $fs->remove($tmpDir);
            }
        });
        if (function_exists('pcntl_signal')) {
            declare(ticks = 1);
            /** @noinspection PhpComposerExtensionStubsInspection */
            pcntl_signal(SIGINT, function () use ($tmpDir, $fs) {
                if (file_exists($tmpDir)) {
                    $this->stdErr->writeln("\nCleaning up");
                    $fs->remove($tmpDir);
                }
                exit(130);
            });
        }

        $archiveDir = $tmpDir . '/' . $archiveId;
        if (!mkdir($archiveDir)) {
            $this->stdErr->writeln(sprintf('Failed to create archive directory: <error>%s</error>', $archiveDir));

            return 1;
        }
        $this->debug('Using archive directory: ' . $archiveDir);

        $metadata = [
            'time' => date('c'),
            'version' => self::ARCHIVE_VERSION,
            'cli_version' => $this->config()->getVersion(),
            'project' => $this->getSelectedProject()->getProperties(),
            'environment' => $environment->getProperties(),
            'deployment' => $deployment->getProperties(),
        ];

        if ($includeVariables) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('Copying project-level variables');
            foreach ($this->getSelectedProject()->getVariables() as $var) {
                $metadata['variables']['project'][$var->name] = $var->getProperties();
                if ($var->is_sensitive) {
                    $this->stdErr->writeln(sprintf('  Warning: cannot save value for sensitive project-level variable <comment>%s</comment>', $var->name));
                }
            }

            $this->stdErr->writeln('');
            $this->stdErr->writeln('Copying environment-level variables');
            foreach ($environment->getVariables() as $envVar) {
                $metadata['variables']['environment'][$envVar->name] = $envVar->getProperties();
                if ($envVar->is_sensitive) {
                    $this->stdErr->writeln(sprintf('  Warning: cannot save value for sensitive environment-level variable <comment>%s</comment>', $envVar->name));
                }
            }
        }

        foreach ($supportedServices as $serviceName => $type) {
            $this->stdErr->writeln('');
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

                return 1;
            }
            if ($type === 'mysql' || $type === 'pgsql') {
                // Get a list of schemas from the service configuration.
                $service = $deployment->getService($serviceName);
                $schemas = !empty($service->configuration['schemas'])
                    ? $service->configuration['schemas']
                    : [];

                // Filter the list by the schemas accessible from the endpoint.
                if (isset($service->configuration['endpoints'][$relationshipName]['privileges'])) {
                    $schemas = array_intersect(
                        $schemas,
                        array_keys($service->configuration['endpoints'][$relationshipName]['privileges'])
                    );
                }

                mkdir($archiveDir . '/services/' . $serviceName, 0755, true);

                if (count($schemas) === 0) {
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

                        return $exitCode;
                    }
                    $metadata['services'][$serviceName]['_type'] = $type;
                    $metadata['services'][$serviceName]['dumps'][] = [
                        'filename' => 'services/' . $serviceName . '/' . $filename,
                        'app' => $appName,
                        'schema' => $schema,
                        'relationship' => $relationshipName,
                    ];
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

                    return $exitCode;
                }
                (new Filesystem())->dumpFile($filename, $buffer->fetch());
                $metadata['services'][$serviceName]['_type'] = $type;
                $metadata['services'][$serviceName] += [
                    'filename' => 'services/' . $serviceName . '/' . $filename,
                    'app' => $appName,
                    'relationship' => $relationshipName,
                ];
            }
        }

        if ($hasMounts && !$excludeMounts) {
            /** @var \Platformsh\Cli\Service\Mount $mountService */
            $mountService = $this->getService('mount');
            foreach ($apps as $app) {
                $sourcePaths = [];
                $mounts = $mountService->normalizeMounts($app->getMounts());
                foreach ($mounts as $path => $mount) {
                    if (isset($metadata['mounts'][$path])) {
                        continue;
                    }
                    if ($mount['source'] === 'local' && isset($mount['source_path'])) {
                        if (isset($sourcePaths[$mount['source_path']])) {
                            continue;
                        }
                        $sourcePaths[$mount['source_path']] = true;
                    }
                    $this->stdErr->writeln('');
                    $this->stdErr->writeln('Copying from mount <info>' . $path . '</info>');
                    $source = ltrim($path, '/');
                    $destination = $archiveDir . '/mounts/' . trim($path, '/');
                    mkdir($destination, 0755, true);
                    $this->rsync($app->getSshUrl(), $source, $destination);
                    $metadata['mounts'][$path] = [
                        'app' => $app->getName(),
                        'path' => 'mounts/' . trim($path, '/'),
                    ];
                }
            }
        }

        $this->stdErr->writeln('');
        $this->stdErr->writeln('Writing metadata');
        (new Filesystem())->dumpFile($archiveDir . '/archive.json', json_encode($metadata, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));

        $this->stdErr->writeln('');
        $this->stdErr->writeln('Packing and compressing archive');
        $fs->archiveDir($tmpDir, $archiveFilename);

        $this->stdErr->writeln('');
        $this->stdErr->writeln('Archive: <info>' . $archiveFilename . '</info>');

        if (($absolute = realpath($archiveFilename)) && ($projectRoot = $this->getProjectRoot()) && strpos($absolute, $projectRoot) === 0) {
            /** @var \Platformsh\Cli\Service\Git $git */
            $git = $this->getService('git');
            if (!$git->checkIgnore($absolute, $projectRoot)) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('<comment>Warning: the archive file is not excluded by Git</comment>');
                if ($pos = strrpos($archiveFilename, '.tar.gz')) {
                    $extension = substr($archiveFilename, $pos);
                    $this->stdErr->writeln('  You should probably exclude these files using .gitignore:');
                    $this->stdErr->writeln('    *' . $extension);
                }
            }
        }

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

        $shell->execute($params, null, true, false, [], null);
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
