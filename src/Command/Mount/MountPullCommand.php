<?php

namespace Platformsh\Cli\Command\Mount;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MountPullCommand extends MountSyncCommandBase
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('mount:pull')
            ->setAliases(['mpull'])
            ->setDescription('Download the contents of a mount')
            ->addOption('mount', null, InputOption::VALUE_REQUIRED, 'The mount (as an app-relative path)')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'The directory to which files will be downloaded');
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

        $sshUrl = $this->getSelectedEnvironment()
            ->getSshUrl($this->selectApp($input));

        $appConfig = $this->getAppConfig($sshUrl);

        if (empty($appConfig['mounts'])) {
            $this->stdErr->writeln(sprintf('The app "%s" doesn\'t define any mounts.', $appConfig['name']));

            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        if ($input->getOption('mount')) {
            $mountPath = $this->validateMountPath($input->getOption('mount'), $appConfig['mounts']);
        } elseif ($input->isInteractive()) {
            $mountPath = $questionHelper->choose(
                $this->getMountsAsOptions($appConfig['mounts']),
                'Enter a number to choose a mount to download from:'
            );
        } else {
            $this->stdErr->writeln('The <error>--mount</error> option must be specified (in non-interactive mode).');

            return 1;
        }

        $target = null;
        if ($input->getOption('target')) {
            $target = $input->getOption('target');
        } elseif ($projectRoot = $this->getProjectRoot()) {
            if ($sharedPath = $this->getSharedPath($mountPath, $appConfig['mounts'])) {
                if (file_exists($projectRoot . '/' . $this->config()->get('local.shared_dir') . '/' . $sharedPath)) {
                    $target = $projectRoot . '/' . $this->config()->get('local.shared_dir') . '/' . $sharedPath;
                }
            }

            $target = $questionHelper->askInput('Target directory', $target);
        }
        if (empty($target)) {
            $this->stdErr->writeln('The target directory must be specified.');

            return 1;
        }

        $this->validateDirectory($target);

        $this->runSync($sshUrl, $mountPath, $target, false);

        return 0;
    }
}
