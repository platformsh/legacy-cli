<?php
declare(strict_types=1);

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

class MountDownloadCommand extends CommandBase
{

    protected static $defaultName = 'mount:download';

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
        $this->setDescription('Download files from a mount, using rsync')
            ->addOption('mount', 'm', InputOption::VALUE_REQUIRED, 'The mount (as an app-relative path)')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'The directory to which files will be downloaded')
            ->addOption('delete', null, InputOption::VALUE_NONE, 'Whether to delete extraneous files in the target directory')
            ->addOption('exclude', null, InputOption::VALUE_IS_ARRAY|InputOption::VALUE_REQUIRED, 'File(s) to exclude from the download (pattern)')
            ->addOption('include', null, InputOption::VALUE_IS_ARRAY|InputOption::VALUE_REQUIRED, 'File(s) to include in the download (pattern)')
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
                'Enter a number to choose a mount to download from:'
            );
        } else {
            $this->stdErr->writeln('The <error>--mount</error> option must be specified (in non-interactive mode).');

            return 1;
        }

        $target = null;
        $defaultTarget = null;
        if ($input->getOption('target')) {
            $target = (string) $input->getOption('target');
        } elseif ($projectRoot = $this->selector->getProjectRoot()) {
            $sharedMounts = $this->mountService->getSharedFileMounts($appConfig);
            if (isset($sharedMounts[$mountPath])) {
                if (file_exists($projectRoot . '/' . $this->config->get('local.shared_dir') . '/' . $sharedMounts[$mountPath])) {
                    $defaultTarget = $projectRoot . '/' . $this->config->get('local.shared_dir') . '/' . $sharedMounts[$mountPath];
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
                $defaultTarget = $appPath . '/' . $mountPath;
            }
        }

        if (empty($target)) {
            $questionText = 'Target directory';
            if ($defaultTarget !== null) {
                $formattedDefaultTarget = $this->filesystem->formatPathForDisplay($defaultTarget);
                $questionText .= ' <question>[' . $formattedDefaultTarget . ']</question>';
            }
            $questionText .= ': ';
            $target = $this->questionHelper->ask($input, $this->stdErr, new Question($questionText, $defaultTarget));
        }

        if (empty($target)) {
            $this->stdErr->writeln('The target directory must be specified.');

            return 1;
        }

        $this->mountService->validateDirectory($target, true);

        $confirmText = sprintf(
            "\nDownloading files from the remote mount <comment>%s</comment> to <comment>%s</comment>"
            . "\n\nAre you sure you want to continue?",
            $mountPath,
            $this->filesystem->formatPathForDisplay($target)
        );
        if (!$this->questionHelper->confirm($confirmText)) {
            return 1;
        }

        $sshUrl = $selection->getEnvironment()->getSshUrl($appName);
        $this->mountService->runSync($sshUrl, $mountPath, $target, false, [
            'delete' => $input->getOption('delete'),
            'exclude' => $input->getOption('exclude'),
            'include' => $input->getOption('include'),
        ]);

        return 0;
    }
}
