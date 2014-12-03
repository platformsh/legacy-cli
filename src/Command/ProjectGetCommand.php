<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Local\LocalProject;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;

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
            return 1;
        }
        $project = $this->getProject($projectId);
        if (!$project) {
            $output->writeln("<error>Project not found: $projectId</error>");
            return 1;
        }
        $directoryName = $input->getArgument('directory-name');
        if (empty($directoryName)) {
            $directoryName = $projectId;
        }
        if (is_dir($directoryName)) {
            $output->writeln("<error>The project directory '$directoryName' already exists.</error>");
            return 1;
        }
        if ($projectRoot = $this->getProjectRoot()) {
            if (strpos(realpath(dirname($directoryName)), $projectRoot) === 0) {
                $output->writeln("<error>A project cannot be cloned inside another project.</error>");
                return 1;
            }
        }

        $environments = $this->getEnvironments($project, true);

        $environmentOption = $input->getOption('environment');
        if ($environmentOption) {
            if (!isset($environments[$environmentOption])) {
                $output->writeln("<error>Environment not found: $environmentOption</error>");
                return 1;
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

        // Create the directory structure.
        mkdir($directoryName);
        $projectRoot = realpath($directoryName);
        $local = new LocalProject();
        if (!$projectRoot) {
           throw new \Exception('Failed to create project directory: ' . $directoryName);
        }

        $local->createProjectFiles($projectRoot, $projectId);

        $fsHelper = $this->getHelper('fs');

        // Prepare to talk to the Platform.sh repository.
        $projectUriParts = explode('/', str_replace(array('http://', 'https://'), '', $project['uri']));
        $cluster = $projectUriParts[0];
        $gitUrl = "{$projectId}@git.{$cluster}:{$projectId}.git";
        $repositoryDir = $directoryName . '/repository';

        $gitHelper = $this->getHelper('git');
        $gitHelper->setOutput($output);

        // First check if the repo actually exists.
        $repoHead = $gitHelper->execute(array('ls-remote', $gitUrl, 'HEAD'), false);
        if ($repoHead === false) {
            // The ls-remote command failed.
            $fsHelper->rmdir($projectRoot);
            $output->writeln('<error>Failed to connect to the Platform.sh Git server</error>');
            $output->writeln('Please check your SSH credentials or contact Platform.sh support');
            return 1;
        }
        elseif (empty($repoHead)) {
            // The repository doesn't have a HEAD, which means it is empty.
            // We need to create the folder, run git init, and attach the remote.
            mkdir($repositoryDir);
            // Initialize the repo and attach our remotes.
            $output->writeln("<info>Initializing empty project repository...</info>");
            $gitHelper->execute(array('init'), $repositoryDir, true);
            $output->writeln("<info>Adding Platform.sh remote endpoint to Git...</info>");
            $gitHelper->execute(array('remote', 'add', '-m', 'master', 'origin', $gitUrl), $repositoryDir, true);
            $output->writeln("<info>Your repository has been initialized and connected to Platform.sh!</info>");
            $output->writeln("<info>Commit and push to the $environment branch and Platform.sh will build your project automatically.</info>");
            return 0;
        }

        // We have a repo! Yay. Clone it.
        if (!$gitHelper->cloneRepo($gitUrl, $repositoryDir, $environment)) {
            // The clone wasn't successful. Clean up the folders we created
            // and then bow out with a message.
            $fsHelper->rmdir($projectRoot);
            $output->writeln('<error>Failed to clone Git repository</error>');
            $output->writeln('Please check your SSH credentials or contact Platform.sh support');
            return 1;
        }

        $output->writeln("Downloaded <info>$projectId</info> to <info>$directoryName</info>");

        // Allow the build to be skipped.
        if ($input->getOption('no-build')) {
            return 0;
        }

        // Always skip the build if the cloned repository is empty ('.', '..',
        // '.git' being the only found items)
        $files = scandir($directoryName . '/repository');
        if (count($files) <= 3) {
            return 0;
        }

        // Launch the first build.
        /** @var ProjectBuildCommand $buildCommand */
        $buildCommand = $this->getApplication()->find('build');
        $buildSettings = array(
            'environmentId' => $environment,
        );
        try {
            $buildCommand->build($projectRoot, $buildSettings, $output);
        }
        catch (\Exception $e) {
            $output->writeln("<comment>The build failed with an error</comment>");
            $formattedMessage = $this->getHelper('formatter')->formatBlock($e->getMessage(), 'comment');
            $output->writeln($formattedMessage);
        }

        return 0;
    }

}
