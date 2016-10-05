<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentPushCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('environment:push')
            ->setAliases(['push'])
            ->setDescription('Push code to an environment')
            ->addArgument('src', InputArgument::OPTIONAL, 'The source ref: a branch name or commit hash', 'HEAD')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Allow non-fast-forward updates')
            ->addOption('force-with-lease', null, InputOption::VALUE_NONE, 'Allow non-fast-forward updates, if the remote-tracking branch is up to date')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Do everything except actually send the updates')
            ->addOption('no-wait', null, InputOption::VALUE_NONE, 'After pushing, do not wait for build or deploy')
            ->addOption('identity-file', 'i', InputOption::VALUE_REQUIRED, 'Specify an SSH identity file (public key) to use');
        $this->addProjectOption()
            ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }

        $environment = $this->getSelectedEnvironment();
        $source = $input->getArgument('src');

        /** @var \Platformsh\Cli\Helper\QuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');

        if (($environment->is_main || $environment->id === 'master')
            && !$questionHelper->confirm(sprintf(
                'Are you sure you want to push to the <comment>%s</comment> (production) branch?',
                $environment->id
            ))) {
            return 1;
        }

        if (strpos($source, ':') !== false) {
            $this->stdErr->writeln('Invalid ref: ' . $source);
            return 1;
        }

        $project = $this->getSelectedProject();
        if (!$this->api()->getEnvironment($target, $project)) {
            $create = $questionHelper->confirm(sprintf(
                'The target environment <comment>%s</comment> does not exist.'
                . "\n" . 'Create a new (active) environment?',
                $target
            ));
            if (!$create) {
                // @todo add option for creating an inactive environment, when that's possible
                return 1;
            }
            $autoCompleterValues = array_keys($this->api()->getEnvironments($project));
            $parentId = $questionHelper->askInput('Parent environment', 'master', $autoCompleterValues);
            if (!$parent = $this->api()->getEnvironment($parentId, $project)) {
                $this->stdErr->writeln(sprintf('Environment not found: <error>%s</error>', $parentId));
                return 1;
            }
            $this->stdErr->writeln(sprintf(
                'Branching environment <info>%s</info>, based on parent <info>%s</info>',
                $target,
                $parentId
            ));
            $activity = $parent->branch($target, $target);
        }

        $this->stdErr->writeln(sprintf('Pushing <info>%s</info> to the environment <info>%s</info>', $source, $target));

        $gitArgs = [
            'push',
            $this->getSelectedProject()->getGitUrl(),
            $source . ':' . $target,
        ];

        $command = sprintf(
            'git push %s %s:%s',
            escapeshellarg($this->getSelectedProject()->getGitUrl()),
            escapeshellarg($source),
            escapeshellarg($environment->id)
        );

        foreach (['force-with-lease', 'dry-run'] as $option) {
            if ($input->getOption($option)) {
                $command .= ' --' . $option;
            }
        }

        $sshOptions = [];

        $sshOptions[] = "-o 'SendEnv TERM'";

        if ($input->getOption('no-wait')) {
            $sshOptions[] = "-o 'SendEnv PLATFORMSH_PUSH_NO_WAIT'";
            $command = 'PLATFORMSH_PUSH_NO_WAIT=1 ' . $command;
        }
        if ($identityFile = $input->getOption('identity-file')) {
            if (!file_exists($identityFile)) {
                $this->stdErr->writeln('File not found: ' . $identityFile);
                return 1;
            }
            $sshOptions[] = "-o 'IdentityFile '" . escapeshellarg($identityFile);
        }

        if (!empty($sshOptions)) {
            $sshCommand = 'ssh ' . implode(' ', $sshOptions) . ' $*' . "\n";
            $tempFile = $this->writeSshFile($sshCommand);
            $command = 'GIT_SSH=' . escapeshellarg($tempFile) . ' ' . $command;
        }

        try {
            /** @var \Platformsh\Cli\Helper\ShellHelper $shellHelper */
            $shellHelper = $this->getHelper('shell');
            $result = $shellHelper->executeSimple($command, $projectRoot);
        }
        finally {
            if (isset($tempFile)) {
                unlink($tempFile);
            }
        }

        return $result;
    }

    /**
     * @param string $sshCommand
     *
     * @return string
     */
    protected function writeSshFile($sshCommand)
    {
        $tempDir = sys_get_temp_dir();
        $tempFile = tempnam($tempDir, 'cli-git-ssh');
        if (!$tempFile) {
            throw new \RuntimeException('Failed to create temporary file in: ' . $tempDir);
        }
        if (!file_put_contents($tempFile, $sshCommand)) {
            throw new \RuntimeException('Failed to write temporary file: ' . $tempFile);
        }
        if (!chmod($tempFile, 0750)) {
            throw new \RuntimeException('Failed to make temporary SSH command file executable: ' . $tempFile);
        }

        return $tempFile;
    }
}
