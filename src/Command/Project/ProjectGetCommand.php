<?php
namespace Platformsh\Cli\Command\Project;

use Cocur\Slugify\Slugify;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\DependencyMissingException;
use Platformsh\Cli\Exception\ProcessFailedException;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class ProjectGetCommand extends CommandBase
{
    protected function configure()
    {
        $this
            ->setName('project:get')
            ->setAliases(['get'])
            ->setDescription('Clone a project locally')
            ->addArgument('project', InputArgument::OPTIONAL, 'The project ID')
            ->addArgument('directory', InputArgument::OPTIONAL, 'The directory to clone to. Defaults to the project title')
            ->addOption('environment', 'e', InputOption::VALUE_REQUIRED, "The environment ID to clone. Defaults to 'master' or the first available environment")
            ->addOption('depth', null, InputOption::VALUE_REQUIRED, 'Create a shallow clone: limit the number of commits in the history')
            ->addOption('build', null, InputOption::VALUE_NONE, 'Build the project after cloning');
        $this->addProjectOption();
        Ssh::configureInput($this->getDefinition());
        $this->addExample('Clone the project "abc123" into the directory "my-project"', 'abc123 my-project');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var \Platformsh\Cli\Service\Git $git */
        $git = $this->getService('git');
        /** @var \Platformsh\Cli\Local\LocalProject $localProject */
        $localProject = $this->getService('local.project');

        // Validate input options and arguments.
        $this->validateDepth($input);
        $this->mergeProjectArgument($input);
        $this->validateInput($input, false, true, false);

        // Load the main variables we need.
        $project = $this->getSelectedProject();
        $environment = $this->getSelectedEnvironment();
        $projectLabel = $this->api()->getProjectLabel($project);

        // If this is being run from inside a Git repository, suggest setting
        // or switching the remote project.
        $insideCwd = !$input->getArgument('directory')
            || basename($input->getArgument('directory')) === $input->getArgument('directory');
        if ($insideCwd && ($gitRoot = $git->getRoot()) !== false && $input->isInteractive()) {
            $oldProjectRoot = $localProject->getProjectRoot($gitRoot);
            $oldProjectConfig = $oldProjectRoot ? $localProject->getProjectConfig($oldProjectRoot) : false;
            $oldProject = $oldProjectConfig ? $this->api()->getProject($oldProjectConfig['id']) : false;
            if ($oldProjectRoot && $oldProject && $oldProject->id === $project->id) {
                $this->stdErr->writeln(sprintf(
                    'The project %s is already mapped to the directory: <info>%s</info>',
                    $projectLabel,
                    $oldProjectRoot
                ));

                return 0;
            }

            if ($oldProjectRoot !== false) {
                $this->stdErr->writeln(sprintf('There is already a project in this directory: <comment>%s</comment>', $oldProjectRoot));
                if ($oldProject) {
                    $oldProjectLabel = $this->api()->getProjectLabel($oldProject);
                } elseif (isset($oldProjectConfig['id'])) {
                    $oldProjectLabel = '<info>' . $oldProjectConfig['id'] . '</info>';
                } else {
                    // This should never happen.
                    $oldProjectLabel = '[unknown]';
                }
                $questionText = sprintf('Do you want to change the remote project from %s to %s?', $oldProjectLabel, $projectLabel);
            } else {
                $this->stdErr->writeln(sprintf('This directory is already a Git repository: <comment>%s</comment>', $gitRoot));
                $questionText = sprintf('Do you want to set the remote project for this repository to %s?', $projectLabel);
            }

            $this->stdErr->writeln('');

            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            if ($questionHelper->confirm($questionText)) {
                return $this->runOtherCommand('project:set-remote', ['project' => $project->id], $output);
            }

            return 1;
        }

        $projectRoot = $this->chooseDirectory($project, $input);

        /** @var \Platformsh\Cli\Service\Filesystem $fs */
        $fs = $this->getService('fs');
        $projectRootRelative = $fs->makePathRelative($projectRoot, getcwd());

        // Prepare to talk to the remote repository.
        $gitUrl = $project->getGitUrl();

        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');
        $git->ensureInstalled();
        $git->setSshCommand($ssh->getSshCommand());

        // First check if the repo actually exists.
        try {
            $repoExists = $git->remoteRefExists($gitUrl, 'refs/heads/' . $environment->id)
                || $git->remoteRefExists($gitUrl);
        } catch (ProcessFailedException $e) {
            // The ls-remote command failed.
            $this->stdErr->writeln(sprintf(
                'Failed to connect to the Git repository: <error>' . $gitUrl . '</error>'
            ));

            $this->suggestSshRemedies($gitUrl, $e->getProcess());

            return 1;
        }

        // If the remote repository doesn't exist, then locally we need to
        // create the folder, run git init, and attach the remote.
        if (!$repoExists) {
            $this->stdErr->writeln('Creating project directory: <info>' . $projectRootRelative . '</info>');
            if (mkdir($projectRoot) === false) {
                $this->stdErr->writeln('Failed to create the project directory.');

                return 1;
            }

            $this->debug('Initializing the repository');
            $git->init($projectRoot, true);

            $this->debug('Initializing the project');
            $localProject->mapDirectory($projectRoot, $project);

            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'Your project has been initialized and connected to <info>%s</info>!',
                $this->config()->get('service.name')
            ));
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'Commit and push to the <info>master</info> branch of the <info>%s</info> Git remote'
                . ', and %s will build your project automatically.',
                $this->config()->get('detection.git_remote_name'),
                $this->config()->get('service.name')
            ));

            return 0;
        }

        // We have a repo! Yay. Clone it.
        $this->stdErr->writeln('Downloading project ' . $projectLabel);
        $cloneArgs = [
            '--branch',
            $environment->id,
            '--origin',
            $this->config()->get('detection.git_remote_name'),
        ];
        if ($output->isDecorated()) {
            $cloneArgs[] = '--progress';
        }
        if ($input->getOption('depth')) {
            $cloneArgs[] = '--depth';
            $cloneArgs[] = $input->getOption('depth');
            $cloneArgs[] = '--shallow-submodules';
        }
        $cloned = $git->cloneRepo($gitUrl, $projectRoot, $cloneArgs);
        if ($cloned === false) {
            // The clone wasn't successful. Clean up the folders we created
            // and then bow out with a message.
            $this->stdErr->writeln('<error>Failed to clone Git repository</error>');
            $this->stdErr->writeln(sprintf(
                'Please check your SSH credentials or contact %s support',
                $this->config()->get('service.name')
            ));

            return 1;
        }

        $this->debug('Initializing the project');
        $localProject->mapDirectory($projectRoot, $project);
        $this->setProjectRoot($projectRoot);

        $this->debug('Downloading submodules (if any)');
        $git->updateSubmodules(true, $projectRoot);

        $this->stdErr->writeln('');
        $this->stdErr->writeln(sprintf(
            'The project <info>%s</info> was successfully downloaded to: <info>%s</info>',
            $projectLabel,
            $projectRootRelative
        ));

        // Return early if there is no code in the repository.
        if (!glob($projectRoot . '/*', GLOB_NOSORT)) {
            return 0;
        }

        // Ensure that Drush aliases are created.
        if ($this->getApplication()->has('local:drush-aliases') && Drupal::isDrupal($projectRoot)) {
            $this->stdErr->writeln('');
            try {
                $this->runOtherCommand('local:drush-aliases');
            } catch (DependencyMissingException $e) {
                $this->stdErr->writeln(sprintf('<comment>%s</comment>', $e->getMessage()));
            }
        }

        // Launch the first build.
        $success = true;
        if ($input->getOption('build')) {
            // Launch the first build.
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'Building the project locally for the first time. Run <info>%s build</info> to repeat this.',
                $this->config()->get('application.executable')
            ));
            $options = ['no-clean' => true];
            /** @var \Platformsh\Cli\Local\LocalBuild $builder */
            $builder = $this->getService('local.build');
            $success = $builder->build($options, $projectRoot);
        } else {
            $this->stdErr->writeln(sprintf(
                "\nYou can build the project with: "
                . "\n    cd %s"
                . "\n    %s build",
                $projectRootRelative,
                $this->config()->get('application.executable')
            ));
        }

        return $success ? 0 : 1;
    }

    /**
     * @param InputInterface $input
     *
     * @return void
     */
    private function validateDepth(InputInterface $input) {
        if ($input->getOption('depth') !== null && !preg_match('/^[0-9]+$/', $input->getOption('depth'))) {
            throw new InvalidArgumentException('The --depth value must be an integer.');
        }
    }

    /**
     * @param InputInterface $input
     *
     * @return void
     */
    private function mergeProjectArgument(InputInterface $input) {
        if ($input->getOption('project') && $input->getArgument('project')) {
            throw new InvalidArgumentException('You cannot use both the --project option and the <project> argument.');
        }
        if ($projectId = $input->getArgument('project')) {
            $input->setOption('project', $projectId);
        }
    }

    /**
     * @param Project $project
     * @param InputInterface $input
     *
     * @return string
     */
    private function chooseDirectory(Project $project, InputInterface $input) {
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $directory = $input->getArgument('directory');
        if (empty($directory)) {
            $slugify = new Slugify();
            $directory = $project->title ? $slugify->slugify($project->title) : $project->id;
            $directory = $questionHelper->askInput('Directory', $directory, [$directory, $project->id]);
        }

        if (file_exists($directory)) {
            throw new InvalidArgumentException('The destination path already exists: ' . $directory);
        }

        if (!$parent = realpath(dirname($directory))) {
            throw new InvalidArgumentException('Directory not found: ' . dirname($directory));
        }

        /** @var \Platformsh\Cli\Local\LocalProject $localProject */
        $localProject = $this->getService('local.project');
        if ($localProject->getProjectRoot($directory) !== false) {
            throw new InvalidArgumentException('A project cannot be cloned inside another project.');
        }

        return $parent . DIRECTORY_SEPARATOR . basename($directory);
    }

    /**
     * Suggest SSH key commands for the user, if the Git connection fails.
     *
     * @param string $gitUrl
     * @param Process $process
     */
    protected function suggestSshRemedies($gitUrl, Process $process)
    {
        // Remove the path from the git URI to get the SSH part.
        $gitSshUri = '';
        if (strpos($gitUrl, ':') !== false) {
            list($gitSshUri,) = explode(':', $gitUrl, 2);
        }

        /** @var \Platformsh\Cli\Service\SshDiagnostics $sshDiagnostics */
        $sshDiagnostics = $this->getService('ssh_diagnostics');

        // Determine whether the URL is for an internal Git repository, as
        // opposed to a third-party one (like GitLab/GitHub).
        if ($gitSshUri === '' || !$sshDiagnostics->sshHostIsInternal($gitSshUri)) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(
                'Please make sure you have the correct access rights and the repository exists.'
            );
            return;
        }

        $sshDiagnostics->diagnoseFailure($gitSshUri, $process->getExitCode(), $process);
    }
}
