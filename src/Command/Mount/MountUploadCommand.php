<?php

namespace Platformsh\Cli\Command\Mount;

use Platformsh\Cli\Local\LocalApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class MountUploadCommand extends MountCommandBase
{

    protected static $defaultName = 'mount:upload';

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('Upload files to a mount, using rsync')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'A directory containing files to upload')
            ->addOption('mount', 'm', InputOption::VALUE_REQUIRED, 'The mount (as an app-relative path)')
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Whether to delete extraneous files in the mount')
            ->addOption('exclude', null, InputOption::VALUE_IS_ARRAY|InputOption::VALUE_REQUIRED, 'File(s) to exclude from the upload (pattern)')
            ->addOption('include', null, InputOption::VALUE_IS_ARRAY|InputOption::VALUE_REQUIRED, 'File(s) to include in the upload (pattern)')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');
        $this->addProjectOption();
        $this->addEnvironmentOption();
        $this->addAppOption();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $appName = $this->selectApp($input);

        $appConfig = $this->getAppConfig($appName, (bool) $input->getOption('refresh'));

        if (empty($appConfig['mounts'])) {
            $this->stdErr->writeln(sprintf('The app "%s" doesn\'t define any mounts.', $appConfig['name']));

            return 1;
        }
        /** @var \Platformsh\Cli\Service\Mount $mountService */
        $mountService = $this->getService('mount');
        $mounts = $mountService->normalizeMounts($appConfig['mounts']);

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        /** @var \Platformsh\Cli\Service\Filesystem $fs */
        $fs = $this->getService('fs');

        if ($input->getOption('mount')) {
            $mountPath = $mountService->validateMountPath($input->getOption('mount'), $mounts);
        } elseif ($input->isInteractive()) {
            $mountPath = $questionHelper->choose(
                $this->getMountsAsOptions($mounts),
                'Enter a number to choose a mount to upload to:'
            );
        } else {
            $this->stdErr->writeln('The <error>--mount</error> option must be specified (in non-interactive mode).');

            return 1;
        }

        $source = null;
        $defaultSource = null;
        if ($input->getOption('source')) {
            $source = $input->getOption('source');
        } elseif ($projectRoot = $this->getProjectRoot()) {
            $sharedMounts = $mountService->getSharedFileMounts($appConfig);
            if (isset($sharedMounts[$mountPath])) {
                if (file_exists($projectRoot . '/' . $this->config()->get('local.shared_dir') . '/' . $sharedMounts[$mountPath])) {
                    $defaultSource = $projectRoot . '/' . $this->config()->get('local.shared_dir') . '/' . $sharedMounts[$mountPath];
                }
            }

            $applications = LocalApplication::getApplications($projectRoot, $this->config());
            $appPath = $projectRoot;
            foreach ($applications as $path => $candidateApp) {
                if ($candidateApp->getName() === $appName) {
                    $appPath = $path;
                    break;
                }
            }
            if (is_dir($appPath . '/' . $mountPath)) {
                $defaultSource = $appPath . '/' . $mountPath;
            }
        }

        if (empty($source)) {
            $questionText = 'Source directory';
            if ($defaultSource !== null) {
                $formattedDefaultSource = $fs->formatPathForDisplay($defaultSource);
                $questionText .= ' <question>[' . $formattedDefaultSource . ']</question>';
            }
            $questionText .= ': ';
            $source = $questionHelper->ask($input, $this->stdErr, new Question($questionText, $defaultSource));
        }

        if (empty($source)) {
            $this->stdErr->writeln('The source directory must be specified.');

            return 1;
        }

        $this->validateDirectory($source);

        $confirmText = sprintf(
            "\nUploading files from <comment>%s</comment> to the remote mount <comment>%s</comment>"
            . "\n\nAre you sure you want to continue?",
            $fs->formatPathForDisplay($source),
            $mountPath
        );
        if (!$questionHelper->confirm($confirmText)) {
            return 1;
        }

        $this->runSync($appName, $mountPath, $source, true, [
            'delete' => $input->getOption('delete'),
            'exclude' => $input->getOption('exclude'),
            'include' => $input->getOption('include'),
        ]);

        return 0;
    }
}
