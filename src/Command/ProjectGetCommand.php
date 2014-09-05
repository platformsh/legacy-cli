<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
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
                'The project id'
            )
            ->addArgument(
                'directory-name',
                InputArgument::OPTIONAL,
                'The directory name. Defaults to the project id if not provided'
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

        $environments = $this->getEnvironments($project);
        // Create a numerically indexed list, starting with "master".
        $environmentList = array($environments['master']);
        foreach ($environments as $environment) {
            if ($environment['id'] != 'master' && (!array_key_exists('#activate', $environment['_links']) || $input->getOption('include-inactive'))) {
                $environmentList[] = $environment;
            }
        }

        $environmentIndex = 0;
        if (count($environmentList) > 1) {
            $chooseEnvironmentText = "Enter a number to choose which environment to checkout: \n";
            foreach ($environmentList as $index => $environment) {
                $chooseEnvironmentText .= "[$index] : " . $environment['title'] . "\n";
            }
            $dialog = $this->getHelperSet()->get('dialog');
            $validator = function ($enteredIndex) use ($environmentList) {
                if (!isset($environmentList[$enteredIndex])) {
                    $max = count($environmentList) - 1;
                    throw new \RunTimeException("Please enter a number between 0 and $max.");
                }
                return $enteredIndex;
            };
            $environmentIndex = $dialog->askAndValidate($output, $chooseEnvironmentText, $validator, false, 0);
        }
        $environment = $environmentList[$environmentIndex]['id'];

        // Create the directory structure
        $folders = array();
        $folders[] = $directoryName;
        $folders[] = $directoryName . '/builds';
        $folders[] = $directoryName . '/shared';
        foreach ($folders as $folder) {
            mkdir($folder);
        }

        // Create the settings.local.php file.
        // @todo Find a better place for this, since it's Drupal specific.
        copy(CLI_ROOT . '/resources/drupal/settings.local.php', $directoryName . '/shared/settings.local.php');

        // Create the .platform-project file.
        $projectConfig = array(
            'id' => $projectId,
        );
        $dumper = new Dumper();
        file_put_contents($directoryName . '/.platform-project', $dumper->dump($projectConfig));

        // Clone the repository.
        $projectUriParts = explode('/', str_replace(array('http://', 'https://'), '', $project['uri']));
        $cluster = $projectUriParts[0];
        $gitUrl = "{$projectId}@git.{$cluster}:{$projectId}.git";
        $repositoryDir = $directoryName . '/repository';
        $command = "git clone --branch $environment $gitUrl $repositoryDir";
        passthru($command);
        if (!is_dir($directoryName . '/repository')) {
            // The clone wasn't successful. Clean up the folders we created
            // and then bow out with a message.
            foreach (array_reverse($folders) as $folder) {
              $this->rmdir($folder);
            }
            $formatter = $this->getHelper('formatter');
            $errorArray = array(
              "[Error]",
              "We're sorry, your Platform project could not be cloned.",
              "Please check your SSH credentials or contact Platform Support."
            );
            $errorBlock = $formatter->formatBlock($errorArray, 'error', TRUE);
            $output->writeln($errorBlock);
            return;
        }

        // Create the .gitignore file.
        // @todo Make the Platform itself responsible for this?
        copy(CLI_ROOT . '/resources/drupal/gitignore', $directoryName . '/repository/.gitignore');

        // Allow the build to be skipped, and always skip it if the cloned
        // repository is empty ('.', '..', '.git' being the only found items).
        $noBuild = $input->getOption('no-build');
        $files = scandir($directoryName . '/repository');
        if (!$noBuild && count($files) > 3) {
            // Launch the first build.
            $application = $this->getApplication();
            $projectRoot = realpath($directoryName);
            try {
                $buildCommand = $application->find('build');
                $buildCommand->build($projectRoot, $environment);
            } catch (\Exception $e) {
                $environmentName = $environmentList[$environmentIndex]['title'];
                $output->writeln("<comment>The '$environmentName' environment could not be built: \n" . $e->getMessage() . "</comment>");
            }
        }
    }
}
