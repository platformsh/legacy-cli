<?php
namespace Platformsh\Cli\Command\Project;

use Cocur\Slugify\Slugify;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\DependencyMissingException;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Service\Ssh;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectGetCommand extends CommandBase
{
    protected $projectRoot;

    protected function configure()
    {
        $this
            ->setName('project:get')
            ->setAliases(['get'])
            ->setDescription('Clone a project locally')
            ->addArgument('project', InputArgument::OPTIONAL, 'The project ID')
            ->addArgument('directory', InputArgument::OPTIONAL, 'The directory to clone to. Defaults to the project title');
        $this->addProjectOption();
        $this->addOption('environment', 'e', InputOption::VALUE_REQUIRED, "The environment ID to clone. Defaults to 'master' or the first available environment")
            ->addOption('host', null, InputOption::VALUE_REQUIRED, "The project's API hostname")
            ->addOption('build', null, InputOption::VALUE_NONE, 'Build the project after cloning');
        Ssh::configureInput($this->getDefinition());
        $this->addExample('Clone the project "abc123" into the directory "my-project"', 'abc123 my-project');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $project = $this->getSelectedProject();
        $environment = $this->getSelectedEnvironment();
        $projectRoot = $this->projectRoot;

        // Prepare to talk to the remote repository.
        $gitUrl = $project->getGitUrl();

        /** @var \Platformsh\Cli\Service\Git $git */
        $git = $this->getService('git');
        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');
        /** @var \Platformsh\Cli\Service\Filesystem $fs */
        $fs = $this->getService('fs');

        $projectRootRelative = $fs->makePathRelative($projectRoot, getcwd());

        $git->ensureInstalled();
        $git->setSshCommand($ssh->getSshCommand());

        // First check if the repo actually exists.
        try {
            $repoExists = $git->remoteRepoExists($gitUrl);
        } catch (\RuntimeException $e) {
            // The ls-remote command failed.
            $this->stdErr->writeln(sprintf(
                '<error>Failed to connect to the %s Git server</error>',
                $this->config()->get('service.name')
            ));

            $this->suggestSshRemedies();

            return 1;
        }

        /** @var \Platformsh\Cli\Local\LocalProject $localProject */
        $localProject = $this->getService('local.project');

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
        $projectLabel = $this->api()->getProjectLabel($project);
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
     * {@inheritdoc}
     */
    protected function validateInput(InputInterface $input, $envNotRequired = false)
    {
        if ($input->getOption('project') && $input->getArgument('project')) {
            throw new InvalidArgumentException('You cannot use both the --project option and the <project> argument.');
        }
        $projectId = $input->getOption('project') ?: $input->getArgument('project');
        $environmentId = $input->getOption('environment');
        $host = $input->getOption('host');
        if (empty($projectId)) {
            if ($input->isInteractive() && ($projects = $this->api()->getProjects(true))) {
                $projectId = $this->offerProjectChoice($projects, 'Enter a number to choose which project to clone:');
            } else {
                throw new InvalidArgumentException('No project specified');
            }
        } else {
            /** @var \Platformsh\Cli\Service\Identifier $identifier */
            $identifier = $this->getService('identifier');
            $result = $identifier->identify($projectId);
            $projectId = $result['projectId'];
            $host = $host ?: $result['host'];
            $environmentId = $environmentId ?: $result['environmentId'];
        }

        $project = $this->selectProject($projectId, $host);

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $directory = $input->getArgument('directory');
        if (empty($directory)) {
            $slugify = new Slugify();
            $directory = $project->title ? $slugify->slugify($project->title) : $project->id;
            $directory = $questionHelper->askInput('Directory', $directory, [$directory, $projectId]);
        }

        if ($projectRoot = $this->getProjectRoot()) {
            if (strpos(realpath(dirname($directory)), $projectRoot) === 0) {
                throw new InvalidArgumentException('A project cannot be cloned inside another project.');
            }
        }

        if (file_exists($directory)) {
            throw new InvalidArgumentException('The directory already exists: ' . $directory);
        }
        if (!$parent = realpath(dirname($directory))) {
            throw new InvalidArgumentException("Not a directory: " . dirname($directory));
        }
        $this->projectRoot = $parent . '/' . basename($directory);

        if (!$environmentId) {
            $environments = $this->api()->getEnvironments($project);
            $environmentId = isset($environments['master']) ? 'master' : key($environments);
            if (count($environments) > 1) {
                $environmentId = $questionHelper->askInput('Environment', $environmentId, array_keys($environments));
            }
        }

        $this->selectEnvironment($environmentId);
    }

    /**
     * Suggest SSH key commands for the user, if the Git connection fails.
     */
    protected function suggestSshRemedies()
    {
        $sshKeys = [];
        try {
            $sshKeys = $this->api()->getClient(false)->getSshKeys();
        } catch (\Exception $e) {
            // Ignore exceptions.
        }

        if (!empty($sshKeys)) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('Please check your SSH credentials');
            $this->stdErr->writeln(sprintf(
                'You can list your keys with: <comment>%s ssh-keys</comment>',
                $this->config()->get('application.executable')
            ));
        } else {
            $this->stdErr->writeln(sprintf(
                'You probably need to add an SSH key, with: <comment>%s ssh-key:add</comment>',
                $this->config()->get('application.executable')
            ));
        }
    }
}
