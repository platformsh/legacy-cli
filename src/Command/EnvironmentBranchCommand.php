<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentBranchCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:branch')
            ->setAliases(array('branch'))
            ->setDescription('Branch an environment.')
            ->addArgument(
                'branch-name',
                InputArgument::OPTIONAL,
                'The name of the new branch. For example: "Sprint 2"'
            )
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
            )
            ->addOption(
                'no-build',
                null,
                InputOption::VALUE_NONE,
                "Do not build the new branch"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return;
        }
        if (!$this->operationAllowed('branch')) {
            $output->writeln("<error>Operation not permitted: The current environment can't be branched.</error>");
            return;
        }
        $branchName = $input->getArgument('branch-name');
        if (empty($branchName)) {
            $output->writeln("<error>You must specify the name of the new branch.</error>");
            return;
        }
        $machineName = preg_replace('/[^a-z0-9-]+/i', '', strtolower($branchName));

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $client->branchEnvironment(array('name' => $machineName, 'title' => $branchName));
        // Reload the stored environments, to trigger a drush alias rebuild.
        $this->getEnvironments($this->project);

        // Checkout the new branch locally.
        $projectRoot = $this->getProjectRoot();
        if ($projectRoot) {
          $repositoryDir = $projectRoot . '/repository';
          shell_exec("cd $repositoryDir && git fetch origin && git checkout $machineName");
        }
        else {
          $output->writeln('<comment>Because this command was run from outside your local project root, the new Platform branch could not be checked out in your local Git repository. Make sure to run platform checkout or git checkout in your repository directory to switch to the branch you are expecting.</comment>');
        }

        $noBuild = $input->getOption('no-build');
        if (!$noBuild) {
            // Build the new branch.
            $application = $this->getApplication();
            $projectRoot = $this->getProjectRoot();
            try {
                $buildCommand = $application->find('build');
                $buildCommand->build($projectRoot, $machineName);
            } catch (\Exception $e) {
                $output->writeln("<comment>The new branch could not be built: \n" . $e->getMessage() . "</comment>");
            }
        }

        $message = '<info>';
        $message .= "\nThe environment $branchName has been branched. \n";
        $message .= "</info>";
        $output->writeln($message);
    }
}
