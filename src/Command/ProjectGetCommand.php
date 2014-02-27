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

        $uriParts = explode('/', str_replace('http://', '', $project['uri']));
        $cluster = $uriParts[0];
        $machineName = end($uriParts);
        $gitUrl = "{$machineName}@git.{$cluster}:{$machineName}.git";
        $command = "git clone --branch " . $environment . ' ' . $gitUrl;
        passthru($command);
    }
}
