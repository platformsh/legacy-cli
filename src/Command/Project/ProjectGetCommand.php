<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\SshDiagnostics;
use Cocur\Slugify\Slugify;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\DependencyMissingException;
use Platformsh\Cli\Exception\ProcessFailedException;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'project:get', description: 'Clone a project locally', aliases: ['get'])]
class ProjectGetCommand extends CommandBase
{
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Filesystem $filesystem, private readonly Git $git, private readonly Io $io, private readonly LocalBuild $localBuild, private readonly LocalProject $localProject, private readonly QuestionHelper $questionHelper, private readonly Selector $selector, private readonly SshDiagnostics $sshDiagnostics, private readonly SubCommandRunner $subCommandRunner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('project', InputArgument::OPTIONAL, 'The project ID')
            ->addArgument('directory', InputArgument::OPTIONAL, 'The directory to clone to. Defaults to the project title')
            ->addOption('environment', 'e', InputOption::VALUE_REQUIRED, "The environment ID to clone. Defaults to the project default, or the first available environment")
            ->addOption('depth', null, InputOption::VALUE_REQUIRED, 'Create a shallow clone: limit the number of commits in the history');
        if ($this->config->isCommandEnabled('local:build')) {
            $this->addOption('build', null, InputOption::VALUE_NONE, 'Build the project after cloning');
        }
        $this->selector->addProjectOption($this->getDefinition());
        $this->addCompleter($this->selector);
        Ssh::configureInput($this->getDefinition());
        $this->addExample('Clone the project "abc123" into the directory "my-project"', 'abc123 my-project');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Validate input options and arguments.
        $this->validateDepth($input);
        $this->mergeProjectArgument($input);
        $selection = $this->selector->getSelection($input, new SelectorConfig(selectDefaultEnv: true, detectCurrentEnv: false));

        // Load the main variables we need.
        $project = $selection->getProject();
        $environment = $selection->getEnvironment();
        $projectLabel = $this->api->getProjectLabel($project);

        // If this is being run from inside a Git repository, suggest setting
        // or switching the remote project.
        $insideCwd = !$input->getArgument('directory')
            || basename((string) $input->getArgument('directory')) === $input->getArgument('directory');
        if ($insideCwd && ($gitRoot = $this->git->getRoot()) !== false && $input->isInteractive()) {
            $oldProjectRoot = $this->localProject->getProjectRoot($gitRoot);
            $oldProjectConfig = $oldProjectRoot ? $this->localProject->getProjectConfig($oldProjectRoot) : false;
            $oldProject = $oldProjectConfig ? $this->api->getProject($oldProjectConfig['id']) : false;
            if ($oldProjectRoot && $oldProject && $oldProject->id === $project->id) {
                $this->stdErr->writeln(sprintf(
                    'The project %s is already mapped to the directory: <info>%s</info>',
                    $projectLabel,
                    $oldProjectRoot,
                ));

                return 0;
            }

            if ($oldProjectRoot !== false) {
                $this->stdErr->writeln(sprintf('There is already a project in this directory: <comment>%s</comment>', $oldProjectRoot));
                if ($oldProject) {
                    $oldProjectLabel = $this->api->getProjectLabel($oldProject);
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

            if ($this->questionHelper->confirm($questionText)) {
                return $this->subCommandRunner->run('project:set-remote', ['project' => $project->id], $output);
            }

            return 1;
        }

        $projectRoot = $this->chooseDirectory($project, $input);
        $projectRootFormatted = $this->filesystem->formatPathForDisplay($projectRoot);

        // Prepare to talk to the remote repository.
        $gitUrl = $project->getGitUrl();

        $this->git->ensureInstalled();

        // First check if the repo actually exists.
        try {
            $repoExists = $this->git->remoteRefExists($gitUrl, 'refs/heads/' . $environment->id)
                || $this->git->remoteRefExists($gitUrl);
        } catch (ProcessFailedException $e) {
            // The ls-remote command failed.
            $this->stdErr->writeln('Failed to connect to the Git repository: <error>' . $gitUrl . '</error>');

            // Display the error from the Git process.
            $process = $e->getProcess();
            $errorOutput = $process->getErrorOutput();
            $this->stdErr->writeln('');
            $this->stdErr->writeln($errorOutput);
            $this->suggestSshRemedies($gitUrl, $process);

            return 1;
        }

        // If the remote repository doesn't exist, then locally we need to
        // create the folder, run git init, and attach the remote.
        if (!$repoExists) {
            $this->stdErr->writeln('Creating project directory: <info>' . $projectRootFormatted . '</info>');
            if (mkdir($projectRoot) === false) {
                $this->stdErr->writeln('Failed to create the project directory.');

                return 1;
            }

            $this->io->debug('Initializing the repository');
            $this->git->init($projectRoot, $project->default_branch, true);

            $this->io->debug('Initializing the project');
            $this->localProject->mapDirectory($projectRoot, $project);

            if ($this->git->getCurrentBranch($projectRoot) != $project->default_branch) {
                $this->io->debug('current branch does not match the default_branch, create it.');
                $this->git->checkOutNew($project->default_branch, null, null, $projectRoot);
            }

            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'Your project has been initialized and connected to <info>%s</info>!',
                $this->config->getStr('service.name'),
            ));
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'Commit and push to the <info>%s</info> branch of the <info>%s</info> Git remote'
                . ', and %s will build your project automatically.',
                $project->default_branch,
                $this->config->getStr('detection.git_remote_name'),
                $this->config->getStr('service.name'),
            ));

            return 0;
        }

        // We have a repo! Yay. Clone it.
        $this->stdErr->writeln('Downloading project ' . $projectLabel);
        $cloneArgs = [
            '--branch',
            $environment->id,
            '--origin',
            $this->config->getStr('detection.git_remote_name'),
        ];
        if ($this->stdErr->isDecorated() && $this->io->isTerminal(STDERR)) {
            $cloneArgs[] = '--progress';
        }
        if ($input->getOption('depth')) {
            $cloneArgs[] = '--depth';
            $cloneArgs[] = $input->getOption('depth');
            $cloneArgs[] = '--shallow-submodules';
        }
        $cloned = $this->git->cloneRepo($gitUrl, $projectRoot, $cloneArgs);
        if ($cloned === false) {
            // The clone wasn't successful. Clean up the folders we created
            // and then bow out with a message.
            $this->stdErr->writeln('<error>Failed to clone Git repository</error>');
            $this->stdErr->writeln(sprintf(
                'Please check your SSH credentials or contact %s support',
                $this->config->getStr('service.name'),
            ));

            return 1;
        }

        $this->io->debug('Initializing the project');
        $this->localProject->mapDirectory($projectRoot, $project);

        $this->io->debug('Downloading submodules (if any)');
        $this->git->updateSubmodules(true, $projectRoot);

        $this->stdErr->writeln('');
        $this->stdErr->writeln(sprintf(
            'The project <info>%s</info> was successfully downloaded to: <info>%s</info>',
            $projectLabel,
            $projectRootFormatted,
        ));

        // Return early if there is no code in the repository.
        if (!glob($projectRoot . '/*', GLOB_NOSORT)) {
            return 0;
        }

        // Ensure that Drush aliases are created.
        if ($this->getApplication()->has('local:drush-aliases') && Drupal::isDrupal($projectRoot)) {
            $this->stdErr->writeln('');
            try {
                $this->subCommandRunner->run('local:drush-aliases');
            } catch (DependencyMissingException $e) {
                $this->stdErr->writeln(sprintf('<comment>%s</comment>', $e->getMessage()));
            }
        }

        // Launch the first build.
        $success = true;
        if ($input->hasOption('build') && $input->getOption('build')) {
            // Launch the first build.
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'Building the project locally for the first time. Run <info>%s build</info> to repeat this.',
                $this->config->getStr('application.executable'),
            ));
            $options = ['no-clean' => true];
            $builder = $this->localBuild;
            $success = $builder->build($options, $projectRoot);
        }

        return $success ? 0 : 1;
    }

    /**
     * @param InputInterface $input
     *
     * @return void
     */
    private function validateDepth(InputInterface $input): void
    {
        if ($input->getOption('depth') !== null && !preg_match('/^[0-9]+$/', (string) $input->getOption('depth'))) {
            throw new InvalidArgumentException('The --depth value must be an integer.');
        }
    }

    /**
     * @param InputInterface $input
     *
     * @return void
     */
    private function mergeProjectArgument(InputInterface $input): void
    {
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
    private function chooseDirectory(Project $project, InputInterface $input): string
    {
        $directory = $input->getArgument('directory');
        if (empty($directory)) {
            $slugify = new Slugify();
            $directory = $project->title ? $slugify->slugify($project->title) : $project->id;
            $directory = $this->questionHelper->askInput('Directory', $directory, [$directory, $project->id]);
        }

        if (file_exists($directory)) {
            throw new InvalidArgumentException('The destination path already exists: ' . $directory);
        }

        if (!$parent = realpath(dirname((string) $directory))) {
            throw new InvalidArgumentException('Directory not found: ' . dirname((string) $directory));
        }
        if ($this->localProject->getProjectRoot($directory) !== false) {
            throw new InvalidArgumentException('A project cannot be cloned inside another project.');
        }

        return $parent . DIRECTORY_SEPARATOR . basename((string) $directory);
    }

    /**
     * Suggests SSH key commands for the user, if the Git connection fails.
     */
    protected function suggestSshRemedies(string $gitUrl, Process $process): void
    {
        // Remove the path from the git URI to get the SSH part.
        $gitSshUri = '';
        if (str_contains($gitUrl, ':')) {
            [$gitSshUri, ] = explode(':', $gitUrl, 2);
        }
        $this->sshDiagnostics->diagnoseFailure($gitSshUri, $process);
    }
}
