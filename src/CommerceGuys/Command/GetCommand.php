<?php

namespace CommerceGuys\Command;

use Guzzle\Http\ClientInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Dumper;

class GetCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('get')
            ->setDescription('Does a git clone of the referenced project.')
            ->addArgument(
                'id',
                InputArgument::OPTIONAL,
                'The platform id'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->hasConfiguration()) {
            $output->writeln("<error>Platform settings not initialized. Please run 'platform init'.</error>");
            return;
        }

        $platformId = $input->getArgument('id');
        if (empty($platformId)) {
            $output->writeln("<error>You must provide a platform id.</error>");
            return;
        }

        $client = $this->getAccountClient();
        $data = $client->getProjects();
        $projects = array();
        foreach ($data['projects'] as $project) {
            $id = preg_replace('/[^a-z0-9-]+/i', '-', strtolower($project['name']));
            $projects[$id] = $project;
        }
        if (!isset($projects[$platformId])) {
            $output->writeln("<error>Platform id not found.</error>");
            return;
        }

        $project = $projects[$platformId];
        $uriParts = explode('/', str_replace('http://', '', $project['uri']));
        $cluster = $uriParts[0];
        $machineName = end($uriParts);

        $gitUrl = "{$machineName}@git.{$cluster}:{$machineName}.git";
        $command = "git clone " . $gitUrl;
        passthru($command);
    }
}
