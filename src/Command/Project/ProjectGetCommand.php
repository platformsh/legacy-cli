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
            if ($input->isInteractive() && ($projects = $this->api()->getProjects(true))) {
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

        $project = $this->api()->getProject($projectId, $hostOption, true);
        if (!$project) {
            $this->stdErr->writeln("<error>Project not found: $projectId</error>");

            return 1;
        }

        $environments = $this->api()->getEnvironments($project);
        if ($environmentOption) {
            if (!isset($environments[$environmentOption])) {
                // Reload the environments list.
                $environments = $this->api()->getEnvironments($project, true);
                if (!isset($environments[$environmentOption])) {
                    $this->stdErr->writeln("Environment not found: <error>$environmentOption</error>");
                }

                return 1;
            }
            $environmentId = $environmentOption;
        } elseif (count($environments) === 1) {
            $environmentId = key($environments);
        } else {
            $environmentId = 'master';
        }

        $directory = $input->getArgument('directory');
        if (empty($directory)) {
            $slugify = new Slugify();
            $directory = $project->title ? $slugify->slugify($project->title) : $project->id;
            /** @var \Platformsh\Cli\Helper\QuestionHelper $questionHelper */
            $questionHelper = $this->getHelper('question');
            $directory = $questionHelper->askInput('Directory', $directory);
        }

        if ($projectRoot = $this->getProjectRoot()) {
            if (strpos(realpath(dirname($directory)), $projectRoot) === 0) {
                $this->stdErr->writeln("<error>A project cannot be cloned inside another project.</error>");

                return 1;
            }
        }

        // Create the directory structure.
        if (file_exists($directory)) {
            $this->stdErr->writeln("The directory <error>$directory</error> already exists");
            return 1;
        }
        if (!$parent = realpath(dirname($directory))) {
            throw new \Exception("Not a directory: " . dirname($directory));
        }
        $projectRoot = $parent . '/' . basename($directory);

        // Prepare to talk to the remote repository.
        $gitUrl = $project->getGitUrl();

        $gitHelper = new GitHelper(new ShellHelper($this->stdErr));
        $gitHelper->ensureInstalled();

        // First check if the repo actually exists.
        try {
            $exists = $gitHelper->remoteRepoExists($gitUrl);
        }
        catch (\Exception $e) {
            // The ls-remote command failed.
            $this->stdErr->writeln('<error>Failed to connect to the ' . self::$config->get('service.name') . ' Git server</error>');

            // Suggest SSH key commands.
            $sshKeys = [];
            try {
                $sshKeys = $this->api()->getClient(false)->getSshKeys();
            }
            catch (\Exception $e) {
                // Ignore exceptions.
            }

            if (!empty($sshKeys)) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('Please check your SSH credentials');
                $this->stdErr->writeln('You can list your keys with: <comment>' . self::$config->get('application.executable') . ' ssh-keys</comment>');
            }
            else {
                $this->stdErr->writeln('You probably need to add an SSH key, with: <comment>' . self::$config->get('application.executable') . ' ssh-key:add</comment>');
            }

            return 1;
        }

        $projectConfig = [
            'id' => $projectId,
        ];
        $host = parse_url($project->getUri(), PHP_URL_HOST);
        if ($host) {
            $projectConfig['host'] = $host;
        }

        // If the remote repository exists, then locally we need to create the
        // folder, run git init, and attach the remote.
        if (!$exists) {
            $this->stdErr->writeln('Creating project directory: <info>' . $directory . '</info>');
            if (mkdir($projectRoot) === false) {
                $this->stdErr->writeln('Failed to create the project directory.');

                return 1;
            }

            // Initialize the repo and attach our remotes.
            $this->debug('Initializing the repository');
            $gitHelper->init($projectRoot, true);

            // As soon as there is a Git repo present, add the project config file.
            $this->localProject->writeCurrentProjectConfig($projectConfig, $projectRoot);

            $this->debug('Adding Git remote(s)');
            $this->localProject->ensureGitRemote($projectRoot, $gitUrl);

            $this->stdErr->writeln('');
            $this->stdErr->writeln('Your project has been initialized and connected to <info>' . self::$config->get('service.name') . '</info>!');
            $this->stdErr->writeln('');
            $this->stdErr->writeln('Commit and push to the <info>master</info> branch of the <info>' . self::$config->get('detection.git_remote_name') . '</info> Git remote, and ' . self::$config->get('service.name') . ' will build your project automatically.');

            return 0;
        }

        // We have a repo! Yay. Clone it.
        $projectLabel = $this->api()->getProjectLabel($project);
        $this->stdErr->writeln('Downloading project ' . $projectLabel);
        $cloneArgs = [
            '--branch',
            $environmentId,
            '--origin',
            self::$config->get('detection.git_remote_name'),
        ];
        if ($output->isDecorated()) {
            $cloneArgs[] = '--progress';
        }
        $cloned = $gitHelper->cloneRepo($gitUrl, $projectRoot, $cloneArgs);
        if ($cloned === false) {
            // The clone wasn't successful. Clean up the folders we created
            // and then bow out with a message.
            $this->stdErr->writeln('<error>Failed to clone Git repository</error>');
            $this->stdErr->writeln('Please check your SSH credentials or contact ' . self::$config->get('service.name') . ' support');

            return 1;
        }

        $this->setProjectRoot($projectRoot);

        $this->localProject->writeCurrentProjectConfig($projectConfig, $projectRoot);
        $this->localProject->ensureGitRemote($projectRoot, $gitUrl);

        $gitHelper->updateSubmodules(true, $projectRoot);

        $this->stdErr->writeln("\nThe project <info>$projectLabel</info> was successfully downloaded to: <info>$directory</info>");

        // Return early if there is no code in the repository.
        if (!glob($projectRoot . '/*', GLOB_NOSORT)) {
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
            $this->stdErr->writeln('Building the project locally for the first time. Run <info>' . self::$config->get('application.executable') . ' build</info> to repeat this.');
            $options = ['no-clean' => true];
            $builder = new LocalBuild($options, self::$config, $output);
            $success = $builder->build($projectRoot);
        }
        else {
            $this->stdErr->writeln(
                "\nYou can build the project with: "
                . "\n    cd $directory"
                . "\n    " . self::$config->get('application.executable') . " build"
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
            $projectList[$project->id] = $this->api()->getProjectLabel($project, false);
        }
        $text = "Enter a number to choose which project to clone:";

        return $this->getHelper('question')
                    ->choose($projectList, $text, $input, $this->stdErr);
    }

}
