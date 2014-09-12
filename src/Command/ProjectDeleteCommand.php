<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

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
            parent::configure();
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

        $client = $this->getPlatformClient($project['uri']);
        $client->deleteProject();

        $message = '<info>';
        $message = "\nThe project #$projectId has been deleted. \n";
        $message .= "</info>";
        $output->writeln($message);
    }
}
