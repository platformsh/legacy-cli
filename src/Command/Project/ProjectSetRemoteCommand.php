<?php
namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Service\Identifier;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'project:set-remote', description: 'Set the remote project for the current Git repository', aliases: ['set-remote'])]
class ProjectSetRemoteCommand extends CommandBase
{
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly \Platformsh\Cli\Service\Filesystem $filesystem, private readonly Git $git, private readonly Identifier $identifier, private readonly Io $io, private readonly LocalProject $localProject, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure()
    {
        $this
            ->addArgument('project', InputArgument::OPTIONAL, 'The project ID');
        $this->addExample('Set the remote project for this repository to "abcdef123456"', 'abcdef123456');
        $this->addExample('Unset the remote project for this repository', '-');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectId = $input->getArgument('project');
        $unset = false;
        if ($projectId === '-') {
            $unset = true;
            $projectId = null;
        }

        if ($projectId) {
            $identifier = $this->identifier;
            $result = $identifier->identify($projectId);
            $projectId = $result['projectId'];
        }

        $git = $this->git;
        $root = $git->getRoot(getcwd());
        if ($root === false) {
            $this->stdErr->writeln(
                'No Git repository found. Use <info>git init</info> to create a repository.'
            );

            return 1;
        }

        $this->io->debug('Git repository found: ' . $root);

        $questionHelper = $this->questionHelper;
        $localProject = $this->localProject;
        $fs = $this->filesystem;

        if ($unset) {
            $configFilename = $root . DIRECTORY_SEPARATOR . $this->config->get('local.project_config');
            if (!\file_exists($configFilename)) {
                $configFilename = null;
            }
            $git->ensureInstalled();
            $gitRemotes = [];
            foreach ([$this->config->get('detection.git_remote_name'), 'origin'] as $remote) {
                $url = $git->getConfig(sprintf('remote.%s.url', $remote));
                if (\is_string($url) && $localProject->parseGitUrl($url) !== false) {
                    $gitRemotes[$remote] = $url;
                }
            }
            if (!$gitRemotes && !$configFilename) {
                $this->stdErr->writeln('This repository is not mapped to a remote project.');
                return 0;
            }
            $this->stdErr->writeln('Unsetting the remote project for this repository');
            $this->stdErr->writeln('');
            if ($configFilename) {
                $this->stdErr->writeln(sprintf('This config file will be deleted: <comment>%s</comment>', $fs->formatPathForDisplay($configFilename)));
            }
            if ($gitRemotes) {
                $this->stdErr->writeln(sprintf('These Git remote(s) will be deleted: <comment>%s</comment>', \implode(', ', \array_keys($gitRemotes))));
            }
            $this->stdErr->writeln('');
            if (!$questionHelper->confirm('Are you sure?')) {
                return 1;
            }
            foreach (array_keys($gitRemotes) as $gitRemote) {
                $git->execute(
                    ['remote', 'rm', $gitRemote],
                    $root,
                    true
                );
            }
            if ($configFilename) {
                (new Filesystem())->remove($configFilename);
            }
            $this->stdErr->writeln('This repository is no longer mapped to a project.');
            return 0;
        }

        $currentProject = $this->selector->getCurrentProject(true);
        if ($currentProject) {
            $this->stdErr->writeln(sprintf(
                'This repository is already linked to the remote project: %s',
                $this->api->getProjectLabel($currentProject, 'comment')
            ));
            if (!$questionHelper->confirm('Are you sure you want to change it?')) {
                return 1;
            }
            $this->stdErr->writeln('');
            $this->chooseProjectText = 'Enter a number to choose another project:';
            $this->enterProjectText = 'Enter the ID of another project';
        }

        $asking = $projectId === null;
        $project = $this->selectProject($projectId, null, false);
        if ($asking) {
            $this->stdErr->writeln('');
        }

        if ($currentProject && $currentProject->id === $project->id) {
            $this->stdErr->writeln(sprintf(
                'The remote project for this repository is already set as: %s',
                $this->api->getProjectLabel($currentProject)
            ));

            return 0;
        } elseif ($currentProject) {
            $this->stdErr->writeln(sprintf(
                'Changing the remote project for this repository from %s to %s',
                $this->api->getProjectLabel($currentProject),
                $this->api->getProjectLabel($project)
            ));
            $this->stdErr->writeln('');
        } else {
            $this->stdErr->writeln(sprintf(
                'Setting the remote project for this repository to: %s',
                $this->api->getProjectLabel($project)
            ));
            $this->stdErr->writeln('');
        }

        $localProject->mapDirectory($root, $project);

        $this->stdErr->writeln(sprintf(
            'The remote project for this repository is now set to: %s',
            $this->api->getProjectLabel($project)
        ));

        if ($input->isInteractive()) {
            $currentBranch = $git->getCurrentBranch($root);
            $currentEnvironment = $currentBranch ? $this->api->getEnvironment($currentBranch, $project) : false;
            if ($currentBranch !== false && $currentEnvironment && $currentEnvironment->has_code) {
                $headSha = $git->execute(['rev-parse', '--verify', 'HEAD'], $root);
                if ($currentEnvironment->head_commit === $headSha) {
                    $this->stdErr->writeln(sprintf("\nThe local branch <info>%s</info> is up to date.", $currentBranch));
                } elseif ($questionHelper->confirm("\nDo you want to pull code from the project?")) {
                    $success = $git->pull($project->getGitUrl(), $currentEnvironment->id, $root, false);

                    return $success ? 0 : 1;
                }
            }
        }

        return 0;
    }
}
