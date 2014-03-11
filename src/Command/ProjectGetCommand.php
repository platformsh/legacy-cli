<?php

namespace CommerceGuys\Platform\Cli\Command;

use Guzzle\Http\ClientInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectId = $input->getArgument('id');
        if (empty($projectId)) {
            $output->writeln("<error>You must specify a project.</error>");
            return;
        }
        $projects = $this->getProjects();
        if (!isset($projects[$projectId])) {
            $output->writeln("<error>Project not found.</error>");
            return;
        }
        $project = $projects[$projectId];
        $projectUriParts = explode('/', str_replace(array('http://', 'https://'), '', $project['uri']));
        $id = end($projectUriParts);
        if (is_dir($id)) {
            $output->writeln("<error>The project directory '$id' already exists.</error>");
            return;
        }

        $environments = $this->getEnvironments($project);
        // Create a numerically indexed list, starting with "master".
        $environmentList = array($environments['master']);
        foreach ($environments as $environment) {
            if ($environment['id'] != 'master') {
                $environmentList[] = $environment;
            }
        }

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
        $environment = $environmentList[$environmentIndex]['id'];

        // Create the directory structure
        $folders = array();
        $folders[] = $id;
        $folders[] = $id . '/builds';
        $folders[] = $id . '/repository';
        $folders[] = $id . '/shared';
        foreach ($folders as $folder) {
            mkdir($folder);
        }

        // Create the .platform-project file.
        $projectConfig = array(
            'id' => $id,
        );
        $dumper = new Dumper();
        file_put_contents($id . '/.platform-project', $dumper->dump($projectConfig));

        // Clone the repository.
        $cluster = $projectUriParts[0];
        $gitUrl = "{$id}@git.{$cluster}:{$id}.git";
        $repositoryDir = $id . '/repository';
        $command = "git clone --branch $environment $gitUrl $repositoryDir";
        passthru($command);

        // Launch the first build.
        $application = $this->getApplication();
        $projectRoot = realpath($id);
        try {
            $buildCommand = $application->find('build');
            $buildCommand->build($projectRoot, $environment);
        } catch (\Exception $e) {
            $environmentName = $environmentList[$environmentIndex]['title'];
            $output->writeln("<comment>The '$environmentName' environment could not be built: \n" . $e->getMessage() . "</comment>");
        }
    }
}
