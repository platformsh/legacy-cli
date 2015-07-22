<?php

namespace Platformsh\Cli\Command;

use Cocur\Slugify\Slugify;
use Platformsh\Cli\Helper\GitHelper;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Local\Toolstack\Drupal;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectGetCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('project:get')
          ->setAliases(array('get'))
          ->setDescription('Clone and build a project locally')
          ->addArgument(
            'id',
            InputArgument::OPTIONAL,
            'The project ID'
          )
          ->addArgument(
            'directory-name',
            InputArgument::OPTIONAL,
            'The directory name. Defaults to the project title'
          )
          ->addOption(
            'environment',
            'e',
            InputOption::VALUE_OPTIONAL,
            "The environment ID to clone. Defaults to 'master'"
          )
          ->addOption(
            'no-build',
            null,
            InputOption::VALUE_NONE,
            "Do not build the retrieved project"
          )
          ->addOption(
            'include-inactive',
            null,
            InputOption::VALUE_NONE,
            "List inactive environments too"
          )
          ->addOption(
            'host',
            null,
            InputOption::VALUE_OPTIONAL,
            "The project's API hostname"
          );
        $this->addExample('Clone the project "abc123" into the directory "my-project"', 'abc123 my-project');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectId = $input->getArgument('id');
        if (empty($projectId)) {
            if ($input->isInteractive() && ($projects = $this->getProjects(true))) {
                $projectId = $this->offerProjectChoice($projects, $input);
            } else {
                $this->stdErr->writeln("<error>You must specify a project.</error>");

                return 1;
            }
        }
        $project = $this->getProject($projectId, $input->getOption('host'));
        if (!$project) {
            $this->stdErr->writeln("<error>Project not found: $projectId</error>");

            return 1;
        }

        /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');

        $directoryName = $input->getArgument('directory-name');
        if (empty($directoryName)) {
            $slugify = new Slugify();
            $directoryName = $project->title ? $slugify->slugify($project->title) : $project->id;
            $directoryName = $questionHelper->askInput('Directory name', $input, $this->stdErr, $directoryName);
        }

        if ($projectRoot = $this->getProjectRoot()) {
            if (strpos(realpath(dirname($directoryName)), $projectRoot) === 0) {
                $this->stdErr->writeln("<error>A project cannot be cloned inside another project.</error>");

                return 1;
            }
        }

        /** @var \Platformsh\Cli\Helper\FilesystemHelper $fsHelper */
        $fsHelper = $this->getHelper('fs');

        // Create the directory structure.
        $existed = false;
        if (file_exists($directoryName)) {
            $existed = true;
            $this->stdErr->writeln("The directory <error>$directoryName</error> already exists");
            if ($questionHelper->confirm("Overwrite?", $input, $this->stdErr, false)) {
                $fsHelper->remove($directoryName);
            }
            else {
                return 1;
            }
        }
        mkdir($directoryName);
        $projectRoot = realpath($directoryName);
        if (!$projectRoot) {
            throw new \Exception("Failed to create project directory: $directoryName");
        }

        if ($existed) {
            $this->stdErr->writeln("Re-created project directory: <info>$directoryName</info>");
        }
        else {
            $this->stdErr->writeln("Created new project directory: <info>$directoryName</info>");
        }

        $local = new LocalProject();
        $hostname = parse_url($project->getUri(), PHP_URL_HOST) ?: null;
        $local->createProjectFiles($projectRoot, $projectId, $hostname);

        $environments = $this->getEnvironments($project, true);

        $environmentOption = $input->getOption('environment');
        if ($environmentOption) {
            if (!isset($environments[$environmentOption])) {
                $this->stdErr->writeln("Environment not found: <error>$environmentOption</error>");

                return 1;
            }
            $environment = $environmentOption;
        } elseif (count($environments) === 1) {
            $environment = key($environments);
        } elseif ($environments && $input->isInteractive()) {
            $environment = $this->offerEnvironmentChoice($environments, $input);
        } else {
            $environment = 'master';
        }

        // Prepare to talk to the Platform.sh repository.
        $gitUrl = $project->getGitUrl();
        $repositoryDir = $directoryName . '/' . LocalProject::REPOSITORY_DIR;

        $gitHelper = new GitHelper(new ShellHelper($this->stdErr));
        $gitHelper->ensureInstalled();

        // First check if the repo actually exists.
        $repoHead = $gitHelper->execute(array('ls-remote', $gitUrl, 'HEAD'), false);
        if ($repoHead === false) {
            // The ls-remote command failed.
            $fsHelper->rmdir($projectRoot);
            $this->stdErr->writeln('<error>Failed to connect to the Platform.sh Git server</error>');
            $this->stdErr->writeln('Please check your SSH credentials or contact Platform.sh support');

            return 1;
        } elseif (is_bool($repoHead)) {
            // The repository doesn't have a HEAD, which means it is empty.
            // We need to create the folder, run git init, and attach the remote.
            mkdir($repositoryDir);
            // Initialize the repo and attach our remotes.
            $this->stdErr->writeln("<info>Initializing empty project repository...</info>");
            $gitHelper->execute(array('init'), $repositoryDir, true);
            $this->stdErr->writeln("<info>Adding Platform.sh remote endpoint to Git...</info>");
            $local->ensureGitRemote($repositoryDir, $gitUrl);
            $this->stdErr->writeln("<info>Your repository has been initialized and connected to Platform.sh!</info>");
            $this->stdErr->writeln(
              "<info>Commit and push to the $environment branch and Platform.sh will build your project automatically.</info>"
            );

            return 0;
        }

        // We have a repo! Yay. Clone it.
        $cloneArgs = array('--branch', $environment, '--origin', 'platform');
        $cloned = $gitHelper->cloneRepo($gitUrl, $repositoryDir, $cloneArgs);
        if (!$cloned) {
            // The clone wasn't successful. Clean up the folders we created
            // and then bow out with a message.
            $fsHelper->rmdir($projectRoot);
            $this->stdErr->writeln('<error>Failed to clone Git repository</error>');
            $this->stdErr->writeln('Please check your SSH credentials or contact Platform.sh support');

            return 1;
        }

        $local->ensureGitRemote($repositoryDir, $gitUrl);
        $this->stdErr->writeln("The project <info>{$project->title}</info> was successfully downloaded to: <info>$directoryName</info>");
        $this->setProjectRoot($projectRoot);

        // Ensure that Drush aliases are created.
        if (Drupal::isDrupal($projectRoot . '/' . LocalProject::REPOSITORY_DIR)) {
            $this->runOtherCommand('local:drush-aliases', array('--group' => $directoryName), $input);
        }

        // Allow the build to be skipped.
        if ($input->getOption('no-build')) {
            return 0;
        }

        // Always skip the build if the cloned repository is empty ('.', '..',
        // '.git' being the only found items)
        if (count(scandir($repositoryDir)) <= 3) {
            return 0;
        }

        // Launch the first build.
        $builder = new LocalBuild(array('environmentId' => $environment), $output);
        $success = $builder->buildProject($projectRoot);

        return $success ? 0 : 1;
    }

    /**
     * @param Environment[]   $environments
     * @param InputInterface  $input
     *
     * @return string
     *   The chosen environment ID.
     */
    protected function offerEnvironmentChoice(array $environments, InputInterface $input)
    {
        $includeInactive = $input->hasOption('include-inactive') && $input->getOption('include-inactive');
        // Create a list starting with "master".
        $default = 'master';
        $environmentList = array($default => $environments[$default]['title']);
        foreach ($environments as $environment) {
            $id = $environment->id;
            if ($id != $default && (!$environment->operationAvailable('activate') || $includeInactive)) {
                $environmentList[$id] = $environment['title'];
            }
        }
        if (count($environmentList) === 1) {
            return key($environmentList);
        }

        $text = "Enter a number to choose which environment to check out:";

        return $this->getHelper('question')
                    ->choose($environmentList, $text, $input, $this->stdErr, $default);
    }

    /**
     * @param Project[]       $projects
     * @param InputInterface  $input
     *
     * @return string
     *   The chosen project ID.
     */
    protected function offerProjectChoice(array $projects, InputInterface $input)
    {
        $projectList = array();
        foreach ($projects as $project) {
            $projectList[$project->id] = $project->id . ' (' . $project->title . ')';
        }
        $text = "Enter a number to choose which project to clone:";

        return $this->getHelper('question')
                    ->choose($projectList, $text, $input, $this->stdErr);
    }

}
