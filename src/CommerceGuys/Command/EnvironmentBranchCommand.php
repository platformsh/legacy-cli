<?php

namespace CommerceGuys\Command;

use Guzzle\Http\ClientInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Dumper;

class EnvironmentBranchCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:branch')
            ->setDescription('Branch an environment.')
            ->addArgument(
                'project-id',
                InputArgument::OPTIONAL,
                'The project id'
            )
            ->addArgument(
                'environment-id',
                InputArgument::OPTIONAL,
                'The environment id'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->hasConfiguration()) {
            $output->writeln("<error>Platform settings not initialized. Please run 'platform init'.</error>");
            return;
        }
        if (!$this->validateArguments($input, $output)) {
            return;
        }

        $dialog = $this->getHelperSet()->get('dialog');
        $message = 'Branch @environment as (i.e "Feature 2"): ';
        $message = str_replace('@environment', $this->environment['title'], $message);
        $validator = function ($data) {
            if (empty($data)) {
                throw new \RunTimeException('Please provide a value.');
            }
            return $data;
        };
        $newBranch = $dialog->askAndValidate($output, $message, $validator);
        $machineName = preg_replace('/[^a-z0-9-]+/i', '-', strtolower($newBranch));

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $client->branchEnvironment(array('name' => $machineName, 'title' => $newBranch));

        $message = '<info>';
        $message = "\nThe environment $newBranch has been branched. \n";
        $message .= "</info>";
        $output->writeln($message);
    }
}
