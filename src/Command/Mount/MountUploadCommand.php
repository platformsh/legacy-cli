<?php

namespace Platformsh\Cli\Command\Mount;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\MountService;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class MountUploadCommand extends CommandBase
{
    protected static $defaultName = 'mount:upload';

    private $config;
    private $filesystem;
    private $mountService;
    private $questionHelper;
    private $selector;

    public function __construct(
        Config $config,
        Filesystem $filesystem,
        MountService $mountService,
        QuestionHelper $questionHelper,
        Selector $selector
    ) {
        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->mountService = $mountService;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
    }

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
        $this->selector->addAllOptions($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);

        $appName = $selection->getAppName();
        $appConfig = $this->mountService
            ->getAppConfig($selection->getEnvironment(), $appName, (bool) $input->getOption('refresh'));

        if (empty($appConfig['mounts'])) {
            $this->stdErr->writeln(sprintf('The app "%s" doesn\'t define any mounts.', $appConfig['name']));

            return 1;
        }
        $mounts = $this->mountService->normalizeMounts($appConfig['mounts']);

        if ($input->getOption('mount')) {
            $mountPath = $this->mountService->validateMountPath($input->getOption('mount'), $mounts);
        } elseif ($input->isInteractive()) {
            $mountPath = $this->questionHelper->choose(
                $this->mountService->getMountsAsOptions($mounts),
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
        } elseif ($projectRoot = $this->selector->getProjectRoot()) {
            $sharedMounts = $this->mountService->getSharedFileMounts($appConfig);
            if (isset($sharedMounts[$mountPath])) {
                if (file_exists($projectRoot . '/' . $this->config->get('local.shared_dir') . '/' . $sharedMounts[$mountPath])) {
                    $defaultSource = $projectRoot . '/' . $this->config->get('local.shared_dir') . '/' . $sharedMounts[$mountPath];
                }
            }

            $applications = LocalApplication::getApplications($projectRoot, $this->config);
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
                $formattedDefaultSource = $this->filesystem->formatPathForDisplay($defaultSource);
                $questionText .= ' <question>[' . $formattedDefaultSource . ']</question>';
            }
            $questionText .= ': ';
            $source = $this->questionHelper->ask($input, $this->stdErr, new Question($questionText, $defaultSource));
        }

        if (empty($source)) {
            $this->stdErr->writeln('The source directory must be specified.');

            return 1;
        }

        $this->mountService->validateDirectory($source);

        $confirmText = sprintf(
            "\nUploading files from <comment>%s</comment> to the remote mount <comment>%s</comment>"
            . "\n\nAre you sure you want to continue?",
            $this->filesystem->formatPathForDisplay($source),
            $mountPath
        );
        if (!$this->questionHelper->confirm($confirmText)) {
            return 1;
        }

        $sshUrl = $selection->getEnvironment()->getSshUrl($appName);
        $this->mountService->runSync($sshUrl, $mountPath, $source, true, [
            'delete' => $input->getOption('delete'),
            'exclude' => $input->getOption('exclude'),
            'include' => $input->getOption('include'),
        ]);

        return 0;
    }
}
