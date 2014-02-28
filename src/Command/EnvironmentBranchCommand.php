<?php

namespace CommerceGuys\Platform\Cli\Command;

use Guzzle\Http\ClientInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Dumper;

class EnvironmentBranchCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:branch')
            ->setAliases(array('branch'))
            ->setDescription('Branch an environment.')
            ->addOption(
                'project',
                null,
                InputOption::VALUE_OPTIONAL,
                'The project id'
            )
            ->addOption(
                'environment',
                null,
                InputOption::VALUE_OPTIONAL,
                'The environment id'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return;
        }

        $dialog = $this->getHelperSet()->get('dialog');
        $branchText = 'Branch @environment as (i.e "Feature 2"): ';
        $branchText = str_replace('@environment', $this->environment['title'], $branchText);
        $validator = function ($data) {
            if (empty($data)) {
                throw new \RunTimeException('Please provide a value.');
            }
            return $data;
        };
        $newBranch = $dialog->askAndValidate($output, $branchText, $validator);
        $machineName = preg_replace('/[^a-z0-9-]+/i', '', strtolower($newBranch));

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $client->branchEnvironment(array('name' => $machineName, 'title' => $newBranch));
        // Refresh the stored environments, to trigger a drush alias rebuild.
        $this->getEnvironments($this->project, TRUE);

        // Checkout the new branch locally.
        $projectRoot = $this->getProjectRoot();
        $repositoryDir = $projectRoot . '/repository';
        shell_exec("cd $repositoryDir && git fetch origin && git checkout $machineName");

        $message = '<info>';
        $message = "\nThe environment $newBranch has been branched. \n";
        $message .= "</info>";
        $output->writeln($message);
    }
}
