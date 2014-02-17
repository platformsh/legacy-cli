<?php

namespace CommerceGuys\Command;

use Guzzle\Http\ClientInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Dumper;

class ProjectDeleteCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('project:delete')
            ->setDescription('Delete a project.')
            ->addArgument(
                'id',
                InputArgument::OPTIONAL,
                'The id of the key to delete'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->hasConfiguration()) {
            $output->writeln("<error>Platform settings not initialized. Please run 'platform init'.</error>");
            return;
        }
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
        $client = $this->getPlatformClient($project['uri']);
        $client->deleteProject();

        $message = '<info>';
        $message = "\nThe project #$projectId has been deleted. \n";
        $message .= "</info>";
        $output->writeln($message);
    }
}
