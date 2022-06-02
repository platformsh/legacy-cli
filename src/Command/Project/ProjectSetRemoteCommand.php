<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Project;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\ProjectNotFoundException;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

class ProjectSetRemoteCommand extends CommandBase
{
    protected static $defaultName = 'project:set-remote';

    private $api;
    private $config;
    private $filesystem;
    private $git;
    private $localProject;
    private $questionHelper;
    private $selector;

    public function __construct(
        Api $api,
        Config $config,
        \Platformsh\Cli\Service\Filesystem $filesystem,
        Git $git,
        LocalProject $localProject,
        QuestionHelper $questionHelper,
        Selector $selector
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->filesystem = $filesystem;
        $this->git = $git;
        $this->localProject = $localProject;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setDescription('Set the remote project for the current Git repository')
            ->addArgument('project', InputArgument::OPTIONAL, 'The project ID');
        $this->addExample('Set the remote project for this repository to "abcdef123456"', 'abcdef123456');
        $this->addExample('Unset the remote project for this repository', '-');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectId = $input->getArgument('project');
        $unset = false;
        if ($projectId === '-') {
            $unset = true;
            $projectId = null;
        }

        if (!$unset) {
            $project = (function () use ($projectId) {
                $definition = new InputDefinition();
                $this->selector->addProjectOption($definition);
                $selection = $this->selector->getSelection(new ArrayInput(['--project' => $projectId], $definition));
                return $selection->getProject();
            })();
        }

        $root = $this->git->getRoot(getcwd());
        if ($root === false) {
            $this->stdErr->writeln(
                'No Git repository found. Use <info>git init</info> to create a repository.'
            );

            return 1;
        }

        $this->debug('Git repository found: ' . $root);

        if ($unset) {
            $configFilename = $root . DIRECTORY_SEPARATOR . $this->config->get('local.project_config');
            if (!\file_exists($configFilename)) {
                $configFilename = null;
            }
            $this->git->ensureInstalled();
            $gitRemotes = [];
            foreach ([$this->config->get('detection.git_remote_name'), 'origin'] as $remote) {
                $url = $this->git->getConfig(sprintf('remote.%s.url', $remote));
                if (\is_string($url) && $this->localProject->parseGitUrl($url) !== false) {
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
                $this->stdErr->writeln(sprintf('This config file will be deleted: <comment>%s</comment>', $this->filesystem->formatPathForDisplay($configFilename)));
            }
            if ($gitRemotes) {
                $this->stdErr->writeln(sprintf('These Git remote(s) will be deleted: <comment>%s</comment>', \implode(', ', \array_keys($gitRemotes))));
            }
            $this->stdErr->writeln('');
            if (!$this->questionHelper->confirm('Are you sure?', false)) {
                return 1;
            }
            foreach (array_keys($gitRemotes) as $gitRemote) {
                $this->git->execute(
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

        try {
            $currentProject = $this->selector->getCurrentProject();
        } catch (ProjectNotFoundException $e) {
            $currentProject = false;
        } catch (BadResponseException $e) {
            if ($e->getResponse() && $e->getResponse()->getStatusCode() === 403) {
                $currentProject = false;
            } else {
                throw $e;
            }
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

        $this->localProject->mapDirectory($root, $project);

        $this->stdErr->writeln(sprintf(
            'The remote project for this repository is now set to: %s',
            $this->api->getProjectLabel($project)
        ));

        if ($input->isInteractive()) {
            $currentBranch = $this->git->getCurrentBranch($root);
            $currentEnvironment = $currentBranch ? $this->api->getEnvironment($currentBranch, $project) : false;
            if ($currentBranch !== false && $currentEnvironment && $currentEnvironment->has_code) {
                $headSha = $this->git->execute(['rev-parse', '--verify', 'HEAD'], $root);
                if ($currentEnvironment->head_commit === $headSha) {
                    $this->stdErr->writeln(sprintf("\nThe local branch <info>%s</info> is up to date.", $currentBranch));
                } elseif ($this->questionHelper->confirm("\nDo you want to pull code from the project?")) {
                    $success = $this->git->pull($project->getGitUrl(), $currentEnvironment->id, $root, false);

                    return $success ? 0 : 1;
                }
            }
        }

        return 0;
    }
}
