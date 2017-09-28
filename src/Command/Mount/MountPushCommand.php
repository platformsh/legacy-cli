<?php

namespace Platformsh\Cli\Command\Mount;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MountPushCommand extends MountSyncCommandBase
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('mount:push')
            ->setAliases(['mpush'])
            ->setDescription('Copy (sync) files to a mount')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'The directory containing the files to upload')
            ->addOption('mount', null, InputOption::VALUE_REQUIRED, 'The mount (as an app-relative path)');
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
            $path = $this->validateMountPath($input->getOption('mount'), $appConfig['mounts']);
        } elseif ($input->isInteractive()) {
            $path = $questionHelper->choose(
                $this->getMountsAsOptions($appConfig['mounts']),
                'Enter a number to choose a mount to upload to:'
            );
        } else {
            $this->stdErr->writeln('The <error>--mount</error> option must be specified (in non-interactive mode).');

            return 1;
        }

        $source = null;
        if ($input->getOption('source')) {
            $source = $input->getOption('source');
        } elseif ($projectRoot = $this->getProjectRoot()) {
            if ($sharedPath = $this->getSharedPath($path, $appConfig['mounts'])) {
                if (file_exists($projectRoot . '/' . $this->config()->get('local.shared_dir') . '/' . $sharedPath)) {
                    $source = $projectRoot . '/' . $this->config()->get('local.shared_dir') . '/' . $sharedPath;
                }
            }

            $source = $questionHelper->askInput('Source directory', $source);
        }
        if (empty($source)) {
            $this->stdErr->writeln('The source directory must be specified.');

            return 1;
        }

        $this->validateDirectory($source);

        $this->runSync($sshUrl, $path, $source, true);

        return 0;
    }
}
