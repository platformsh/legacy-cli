<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Yaml\Dumper;

class ProjectGetCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('project:get')
            ->setAliases(array('get'))
            ->setDescription('Does a git clone of the referenced project.')
            ->addArgument(
                'id',
                InputArgument::OPTIONAL,
                'The project ID'
            )
            ->addArgument(
                'directory-name',
                InputArgument::OPTIONAL,
                'The directory name. Defaults to the project ID if not provided'
            )
            ->addOption(
                'environment',
                null,
                InputOption::VALUE_OPTIONAL,
                "The environment ID to clone"
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
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectId = $input->getArgument('id');
        if (empty($projectId)) {
            $output->writeln("<error>You must specify a project.</error>");
            return;
        }
        $project = $this->getProject($projectId);
        if (!$project) {
            $output->writeln("<error>Project not found.</error>");
            return;
        }
        $directoryName = $input->getArgument('directory-name');
        if (empty($directoryName)) {
            $directoryName = $projectId;
        }
        if (is_dir($directoryName)) {
            $output->writeln("<error>The project directory '$directoryName' already exists.</error>");
            return;
        }

        $environments = $this->getEnvironments($project, true);

        $environmentOption = $input->getOption('environment');
        if ($environmentOption) {
            if (!isset($environments[$environmentOption])) {
                $output->writeln("<error>Environment not found: $environmentOption</error>");
                return;
            }
            $environment = $environmentOption;
        }
        elseif (count($environments) > 1 && $input->isInteractive()) {
            // Create a numerically indexed list, starting with "master".
            $environmentList = array($environments['master']['id']);
            foreach ($environments as $environment) {
                if ($environment['id'] != 'master' && (!array_key_exists('#activate', $environment['_links']) || $input->getOption('include-inactive'))) {
                    $environmentList[] = $environment['id'];
                }
            }
            $chooseEnvironmentText = "Enter a number to choose which environment to check out:";
            $helper = $this->getHelper('question');
            $question = new ChoiceQuestion($chooseEnvironmentText, $environmentList);
            $question->setMaxAttempts(5);
            $environment = $helper->ask($input, $output, $question);
        }
        else {
            $environment = 'master';
        }

        // Create the directory structure
        mkdir($directoryName);
        $projectRoot = realpath($directoryName);
        if (!$projectRoot) {
           throw new \Exception('Failed to create project directory: ' . $directoryName);
        }

        mkdir($projectRoot . '/builds');
        mkdir($projectRoot . '/shared');

        // Create the .platform-project file.
        $projectConfig = array(
            'id' => $projectId,
        );
        $dumper = new Dumper();
        file_put_contents($directoryName . '/.platform-project', $dumper->dump($projectConfig));

        // Prepare to talk to the Platform.sh repository.
        $projectUriParts = explode('/', str_replace(array('http://', 'https://'), '', $project['uri']));
        $cluster = $projectUriParts[0];
        $gitUrl = "{$projectId}@git.{$cluster}:{$projectId}.git";
        $repositoryDir = $directoryName . '/repository';
        // First check if the repo actually exists.
        $checkCommand = "git ls-remote $gitUrl HEAD";
        exec($checkCommand, $checkOutput, $checkReturnVar);
        if ($checkReturnVar) {
            // The ls-remote command failed.
            $this->rmdir($projectRoot);
            $output->writeln('<error>Failed to connect to the Platform.sh Git server</error>');
            $output->writeln('Please check your SSH credentials or contact Platform.sh support');
            return 1;
        }
        elseif (!empty($checkOutput)) {
            // We have a repo! Yay. Clone it.
            $command = "git clone --branch $environment $gitUrl " . escapeshellarg($repositoryDir);
            passthru($command);
            if (!is_dir($repositoryDir)) {
                // The clone wasn't successful. Clean up the folders we created
                // and then bow out with a message.
                $this->rmdir($projectRoot);
                $output->writeln('<error>Failed to clone Git repository</error>');
                $output->writeln('Please check your SSH credentials or contact Platform.sh support');
                return 1;
            }

            // Allow the build to be skipped, and always skip it if the cloned
            // repository is empty ('.', '..', '.git' being the only found items).
            $noBuild = $input->getOption('no-build');
            $files = scandir($directoryName . '/repository');
            if (!$noBuild && count($files) > 3) {
                // Launch the first build.
                $application = $this->getApplication();
                $buildCommand = $application->find('build');
                chdir($directoryName);
                return $buildCommand->execute($input, $output);
            }
        }
        else {
            // The repository doesn't have a HEAD, which means it is empty.
            // We need to create the folder, run git init, and attach the remote.
            mkdir($repositoryDir);
            $currentDirectory = getcwd();
            chdir($repositoryDir);
            // Initialize the repo and attach our remotes.
            $output->writeln("<info>Initializing empty project repository...</info>");
            passthru("git init");
            $output->writeln("<info>Adding Platform.sh remote endpoint to Git...</info>");
            passthru("git remote add -m master origin $gitUrl");
            $output->writeln("<info>Your repository has been initialized and connected to Platform.sh!</info>");
            $output->writeln("<info>Commit and push to the $environment branch and Platform.sh will build your project automatically.</info>");
            chdir($currentDirectory);
        }
    }

}
