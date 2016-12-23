<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Client\Exception\EnvironmentStateException;
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
            ->addOption('no-wait', null, InputOption::VALUE_NONE, 'After pushing, do not wait for build or deploy');
        $this->addProjectOption()
            ->addEnvironmentOption();
        Ssh::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, true);
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }

        /** @var \Platformsh\Cli\Service\Git $git */
        $git = $this->getService('git');
        $git->setDefaultRepositoryDir($projectRoot);

        $source = $input->getArgument('src');

        if ($this->hasSelectedEnvironment()) {
            $target = $this->getSelectedEnvironment()->id;
        } elseif ($currentBranch = $git->getCurrentBranch()) {
            $target = $currentBranch;
        } else {
            $this->stdErr->writeln('Could not determine target environment name.');
            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        if ($target === 'master'
            && !$questionHelper->confirm(
                'Are you sure you want to push to the <comment>master</comment> (production) branch?'
            )) {
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

        /** @var \Platformsh\Cli\Local\LocalProject $localProject */
        $localProject = $this->getService('local.project');
        $localProject->ensureGitRemote($projectRoot, $project->getGitUrl());

        $gitArgs = [
            'push',
            $this->config()->get('detection.git_remote_name'),
            $source . ':' . $target,
        ];

        foreach (['force', 'force-with-lease', 'dry-run'] as $option) {
            if ($input->getOption($option)) {
                $gitArgs[] = '--' . $option;
            }
        }

        $extraSshOptions = [];
        $env = [];
        if ($input->getOption('no-wait')) {
            $extraSshOptions[] = 'SendEnv PLATFORMSH_PUSH_NO_WAIT';
            $env['PLATFORMSH_PUSH_NO_WAIT'] = '1';
        }

        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');
        $git->setSshCommand($ssh->getSshCommand($extraSshOptions));

        $result = $git->execute($gitArgs, null, false, false, $env);

        // Clear some caches after pushing.
        if ($result) {
            $this->api()->clearEnvironmentsCache($project->id);
            if ($this->hasSelectedEnvironment()) {
                try {
                    $sshUrl = $this->getSelectedEnvironment()->getSshUrl();
                    /** @var \Platformsh\Cli\Service\Relationships $relationships */
                    $relationships = $this->getService('relationships');
                    $relationships->clearCache($sshUrl);
                } catch (EnvironmentStateException $e) {
                    // Ignore environments with a missing SSH URL.
                }
            }
        }

        return $result ? 0 : 1;
    }
}
