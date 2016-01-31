<?php
namespace Platformsh\Cli\Command\Project;

use Cocur\Slugify\Slugify;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Helper\GitHelper;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Cli\Local\Toolstack\Drupal;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectGetCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('project:get')
            ->setAliases(['get'])
            ->setDescription('Clone a project locally')
            ->addArgument(
                'id',
                InputArgument::OPTIONAL,
                'The project ID'
            )
            ->addArgument(
                'directory',
                InputArgument::OPTIONAL,
                'The directory to clone to. Defaults to the project title'
            )
            ->addOption(
                'environment',
                'e',
                InputOption::VALUE_REQUIRED,
                "The environment ID to clone. Defaults to 'master'"
            )
            ->addOption(
                'host',
                null,
                InputOption::VALUE_REQUIRED,
                "The project's API hostname"
            )
            ->addOption(
                'build',
                null,
                InputOption::VALUE_NONE,
                'Build the project after cloning'
            );
        $this->addExample('Clone the project "abc123" into the directory "my-project"', 'abc123 my-project');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectId = $input->getArgument('id');
        $environmentOption = $input->getOption('environment');
        $hostOption = $input->getOption('host');
        if (empty($projectId)) {
            if ($input->isInteractive() && ($projects = $this->getProjects(true))) {
                $projectId = $this->offerProjectChoice($projects, $input);
            } else {
                $this->stdErr->writeln("<error>You must specify a project.</error>");

                return 1;
            }
        }
        else {
            $result = $this->parseProjectId($projectId);
            $projectId = $result['projectId'];
            $hostOption = $hostOption ?: $result['host'];
            $environmentOption = $environmentOption ?: $result['environmentId'];
        }

        $project = $this->getProject($projectId, $hostOption, true);
        if (!$project) {
            $this->stdErr->writeln("<error>Project not found: $projectId</error>");

            return 1;
        }

        $environments = $this->getEnvironments($project);
        if ($environmentOption) {
            if (!isset($environments[$environmentOption])) {
                // Reload the environments list.
                $environments = $this->getEnvironments($project, true);
                if (!isset($environments[$environmentOption])) {
                    $this->stdErr->writeln("Environment not found: <error>$environmentOption</error>");
                }

                return 1;
            }
            $environment = $environmentOption;
        } elseif (count($environments) === 1) {
            $environment = key($environments);
        } else {
            $environment = 'master';
        }

        $directory = $input->getArgument('directory');
        if (empty($directory)) {
            $slugify = new Slugify();
            $directory = $project->title ? $slugify->slugify($project->title) : $project->id;
            /** @var \Platformsh\Cli\Helper\PlatformQuestionHelper $questionHelper */
            $questionHelper = $this->getHelper('question');
            $directory = $questionHelper->askInput('Directory', $input, $this->stdErr, $directory);
        }

        if ($projectRoot = $this->getProjectRoot()) {
            if (strpos(realpath(dirname($directory)), $projectRoot) === 0) {
                $this->stdErr->writeln("<error>A project cannot be cloned inside another project.</error>");

                return 1;
            }
        }

        /** @var \Platformsh\Cli\Helper\FilesystemHelper $fsHelper */
        $fsHelper = $this->getHelper('fs');

        // Create the directory structure.
        if (file_exists($directory)) {
            $this->stdErr->writeln("The directory <error>$directory</error> already exists");
            return 1;
        }
        if (!$parent = realpath(dirname($directory))) {
            throw new \Exception("Not a directory: " . dirname($directory));
        }
        $projectRoot = $parent . '/' . basename($directory);

        $hostname = parse_url($project->getUri(), PHP_URL_HOST) ?: null;

        // Prepare to talk to the Platform.sh repository.
        $gitUrl = $project->getGitUrl();

        $gitHelper = new GitHelper(new ShellHelper($this->stdErr));
        $gitHelper->ensureInstalled();

        // First check if the repo actually exists.
        $repoHead = $gitHelper->execute(['ls-remote', $gitUrl, 'HEAD'], false);
        if ($repoHead === false) {
            // The ls-remote command failed.
            $this->stdErr->writeln('<error>Failed to connect to the Platform.sh Git server</error>');

            // Suggest SSH key commands.
            $sshKeys = [];
            try {
                $sshKeys = $this->getClient(false)->getSshKeys();
            }
            catch (\Exception $e) {
                // Ignore exceptions.
            }

            if (!empty($sshKeys)) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('Please check your SSH credentials');
                $this->stdErr->writeln('You can list your keys with: <comment>platform ssh-keys</comment>');
            }
            else {
                $this->stdErr->writeln('You probably need to add an SSH key, with: <comment>platform ssh-key:add</comment>');
            }

            return 1;
        } elseif (is_bool($repoHead)) {
            // The repository doesn't have a HEAD, which means it is empty.
            // We need to create the folder, run git init, and attach the remote.
            mkdir($projectRoot);
            // Initialize the repo and attach our remotes.
            $this->stdErr->writeln("Initializing empty project repository");
            $gitHelper->execute(['init'], $projectRoot, true);
            $this->stdErr->writeln("Adding Platform.sh Git remote");
            $this->localProject->ensureGitRemote($projectRoot, $gitUrl);
            $this->stdErr->writeln("Your repository has been initialized and connected to <info>Platform.sh</info>!");
            $this->stdErr->writeln(
                "Commit and push to the <info>$environment</info> branch and Platform.sh will build your project automatically"
            );

            return 0;
        }

        // We have a repo! Yay. Clone it.
        $this->stdErr->writeln(sprintf('Downloading project <info>%s</info>', $project->title ?: $projectId));
        $cloneArgs = ['--branch', $environment, '--origin', 'platform'];
        $cloned = $gitHelper->cloneRepo($gitUrl, $projectRoot, $cloneArgs);
        if (!$cloned) {
            // The clone wasn't successful. Clean up the folders we created
            // and then bow out with a message.
            $this->stdErr->writeln('<error>Failed to clone Git repository</error>');
            $this->stdErr->writeln('Please check your SSH credentials or contact Platform.sh support');

            return 1;
        }

        $gitHelper->updateSubmodules(true, $projectRoot);

        $this->localProject->ensureGitRemote($projectRoot, $gitUrl);
        $this->localProject->writeGitExclude($projectRoot);
        $this->setProjectRoot($projectRoot);

        $this->stdErr->writeln("\nThe project <info>{$project->title}</info> was successfully downloaded to: <info>$directory</info>");

        // Return early if there is no code in the repository.
        if (!glob($projectRoot . '/*')) {
            return 0;
        }

        // Ensure that Drush aliases are created.
        if (Drupal::isDrupal($projectRoot)) {
            $this->stdErr->writeln('');
            $this->runOtherCommand(
                'local:drush-aliases',
                [
                    // The default Drush alias group is the final part of the
                    // directory path.
                    '--group' => basename($directory),
                ]
            );
        }

        // Launch the first build.
        $success = true;
        if ($input->getOption('build')) {
            // Launch the first build.
            $this->stdErr->writeln('');
            $this->stdErr->writeln('Building the project locally for the first time. Run <info>platform build</info> to repeat this.');
            $options = ['environmentId' => $environment, 'noClean' => true];
            $builder = new LocalBuild($options, $output);
            $success = $builder->buildProject($projectRoot);
        }
        else {
            $this->stdErr->writeln(
                "\nYou can build the project with: "
                . "\n    cd $directory"
                . "\n    platform build"
            );
        }

        return $success ? 0 : 1;
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
        $projectList = [];
        foreach ($projects as $project) {
            $projectList[$project->id] = $project->id . ' (' . $project->title . ')';
        }
        $text = "Enter a number to choose which project to clone:";

        return $this->getHelper('question')
                    ->choose($projectList, $text, $input, $this->stdErr);
    }

}
