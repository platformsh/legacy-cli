<?php

namespace Platformsh\Cli\Command\Mount;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Model\RemoteContainer\App;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class MountDownloadCommand extends CommandBase
{
    private $localApps;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('mount:download')
            ->setDescription('Download files from a mount, using rsync')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Download from all mounts')
            ->addOption('mount', 'm', InputOption::VALUE_REQUIRED, 'The mount (as an app-relative path)')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'The directory to which files will be downloaded. If --all is used, the mount path will be appended')
            ->addOption('source-path', null, InputOption::VALUE_NONE, "Use the mount's source path (rather than the mount path) as a subdirectory of the target, when --all is used")
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Whether to delete extraneous files in the target directory')
            ->addOption('exclude', null, InputOption::VALUE_IS_ARRAY|InputOption::VALUE_REQUIRED, 'File(s) to exclude from the download (pattern)')
            ->addOption('include', null, InputOption::VALUE_IS_ARRAY|InputOption::VALUE_REQUIRED, 'File(s) to include in the download (pattern)')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');
        $this->addProjectOption();
        $this->addEnvironmentOption();
        $this->addRemoteContainerOptions();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        /** @var App $container */
        $container = $this->selectRemoteContainer($input);
        /** @var \Platformsh\Cli\Service\Mount $mountService */
        $mountService = $this->getService('mount');
        $mounts = $mountService->mountsFromConfig($container->getConfig());

        if (empty($mounts)) {
            $this->stdErr->writeln(sprintf('No mounts found (host: %s)', $container->getSshUrl()));

            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        /** @var \Platformsh\Cli\Service\Filesystem $fs */
        $fs = $this->getService('fs');

        $all = $input->getOption('all');

        if ($input->getOption('mount')) {
            if ($all) {
                $this->stdErr->writeln('You cannot combine the <error>--mount</error> option with <error>--all</error>.');

                return 1;
            }

            $mountPath = $mountService->matchMountPath($input->getOption('mount'), $mounts);
        } elseif (!$all && $input->isInteractive()) {
            $mountOptions = [];
            foreach ($mounts as $path => $definition) {
                if ($definition['source'] === 'local') {
                    $mountOptions[$path] = sprintf('<question>%s</question>', $path);
                } else {
                    $mountOptions[$path] = sprintf('<question>%s</question>: %s', $path, $definition['source']);
                }
            }
            $mountOptions['\\ALL'] = 'All mounts';

            $choice = $questionHelper->choose(
                $mountOptions,
                'Enter a number to choose a mount to download from:'
            );
            if ($choice === '\\ALL') {
                $all = true;
            } else {
                $mountPath = $choice;
            }
        } elseif (!$all) {
            $this->stdErr->writeln('The <error>--mount</error> option must be specified (in non-interactive mode).');

            return 1;
        }

        $target = null;
        if ($input->getOption('target')) {
            $target = $input->getOption('target');
        }

        if (empty($target) && $input->isInteractive()) {
            $questionText = 'Target directory';
            $defaultTarget = isset($mountPath) ? $this->getDefaultTarget($container, $mountPath) : '.';
            if ($defaultTarget !== null) {
                $formattedDefaultTarget = $fs->formatPathForDisplay($defaultTarget);
                $questionText .= ' <question>[' . $formattedDefaultTarget . ']</question>';
            }
            $questionText .= ': ';
            $target = $questionHelper->ask($input, $this->stdErr, new Question($questionText, $defaultTarget));
            $this->stdErr->writeln('');
        }

        if (empty($target)) {
            $this->stdErr->writeln('The target directory must be specified.');

            return 1;
        }

        if (!file_exists($target)) {
            // Allow rsync to create the target directory if it doesn't
            // already exist.
            if (!$questionHelper->confirm(sprintf('Directory not found: <comment>%s</comment>. Do you want to create it?', $target))) {
                return 1;
            }
            $this->stdErr->writeln('');
        } else {
            $fs->validateDirectory($target, true);
        }

        /** @var \Platformsh\Cli\Service\Rsync $rsync */
        $rsync = $this->getService('rsync');

        $rsyncOptions = [
            'delete' => $input->getOption('delete'),
            'exclude' => $input->getOption('exclude'),
            'include' => $input->getOption('include'),
            'verbose' => $output->isVeryVerbose(),
            'quiet' => $output->isQuiet(),
        ];
        $sshUrl = $container->getSshUrl();

        if ($all) {
            $confirmText = sprintf(
                'Downloading files from all remote mounts to <comment>%s</comment>'
                . "\n\nAre you sure you want to continue?",
                $fs->formatPathForDisplay($target)
            );
            if (!$questionHelper->confirm($confirmText)) {
                return 1;
            }

            $useSourcePath = $input->getOption('source-path');

            foreach ($mounts as $mountPath => $definition) {
                $this->stdErr->writeln('');
                $mountSpecificTarget = $target . '/' . $mountPath;
                if ($useSourcePath) {
                    if (isset($definition['source_path'])) {
                        $mountSpecificTarget = $target . '/' . trim($definition['source_path'], '/');
                    } else {
                        $this->stdErr->writeln('No source path defined for mount <error>' . $mountPath . '</error>');
                    }
                }
                $this->stdErr->writeln(sprintf(
                    'Downloading files from <comment>%s</comment> to <comment>%s</comment>',
                    $mountPath,
                    $fs->formatPathForDisplay($mountSpecificTarget)
                ));
                $fs->mkdir($mountSpecificTarget);
                $rsync->syncDown($sshUrl, $mountPath, $mountSpecificTarget, $rsyncOptions);
            }
        } elseif (isset($mountPath)) {
            $confirmText = sprintf(
                'Downloading files from the remote mount <comment>%s</comment> to <comment>%s</comment>'
                . "\n\nAre you sure you want to continue?",
                $mountPath,
                $fs->formatPathForDisplay($target)
            );
            if (!$questionHelper->confirm($confirmText)) {
                return 1;
            }

            $this->stdErr->writeln('');
            $rsync->syncDown($sshUrl, $mountPath, $target, $rsyncOptions);
        } else {
            throw new \LogicException('Mount path not defined');
        }

        return 0;
    }

    /**
     * @param \Platformsh\Cli\Model\RemoteContainer\App $app
     * @param string                                    $mountPath
     *
     * @return string|null
     */
    private function getDefaultTarget(App $app, $mountPath)
    {
        /** @var \Platformsh\Cli\Service\Mount $mountService */
        $mountService = $this->getService('mount');

        $appPath = $this->getLocalAppPath($app);
        if ($appPath !== null && is_dir($appPath . '/' . $mountPath)) {
            return $appPath . '/' . $mountPath;
        }

        $mounts = $mountService->mountsFromConfig($app->getConfig());
        $sharedMounts = $mountService->getSharedFileMounts($mounts);
        if (isset($sharedMounts[$mountPath])) {
            $sharedDir = $this->getSharedDir($app);
            if ($sharedDir !== null && file_exists($sharedDir . '/' . $sharedMounts[$mountPath])) {
                return $sharedDir . '/' . $sharedMounts[$mountPath];
            }
        }

        return null;
    }

    /**
     * @return LocalApplication[]
     */
    private function getLocalApps()
    {
        if (!isset($this->localApps)) {
            $this->localApps = [];
            if ($projectRoot = $this->getProjectRoot()) {
                $this->localApps = LocalApplication::getApplications($projectRoot, $this->config());
            }
        }

        return $this->localApps;
    }

    /**
     * Returns the local path to an app.
     *
     * @param App $app
     *
     * @return string|null
     */
    private function getLocalAppPath(App $app)
    {
        foreach ($this->getLocalApps() as $path => $candidateApp) {
            if ($candidateApp->getName() === $app->getName()) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param \Platformsh\Cli\Model\RemoteContainer\App $app
     *
     * @return string|null
     */
    private function getSharedDir(App $app)
    {
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            return null;
        }

        $localApps = $this->getLocalApps();
        $dirname =  $projectRoot . '/' . $this->config()->get('local.shared_dir');
        if (count($localApps) > 1 && is_dir($dirname)) {
            $dirname .= $app->getName();
        }

        return file_exists($dirname) ? $dirname : null;
    }
}
