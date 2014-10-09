<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

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
                'force',
                null,
                InputOption::VALUE_NONE,
                "Force creating the branch even if it cannot be checked out locally"
            )
            ->addOption(
                'build',
                null,
                InputOption::VALUE_NONE,
                "Build the new branch locally"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $branchName = $input->getArgument('branch-name');
        if (empty($branchName)) {
            $output->writeln("<error>You must specify the name of the new branch.</error>");
            return 1;
        }

        $machineName = $this->sanitizeEnvironmentId($branchName);

        if ($machineName == $this->environment['id']) {
            $output->writeln("<comment>Already on $machineName</comment>");
            return 1;
        }

        $environments = $this->getEnvironments($this->project);
        if (isset($environments[$machineName])) {
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion("<comment>The environment $machineName already exists.</comment> Checkout? [Y/n] ");
            $checkout = $helper->ask($input, $output, $question);
            if ($checkout) {
                $checkoutCommand = $this->getApplication()->find('environment:checkout');
                return $checkoutCommand->execute($input, $output);
            }
            return 1;
        }

        if (!$this->operationAllowed('branch')) {
            $output->writeln("<error>Operation not permitted: The current environment can't be branched.</error>");
            return 1;
        }

        $force = $input->getOption('force');

        $projectRoot = $this->getProjectRoot();
        if ($projectRoot) {
            $cwd = getcwd();
            chdir($projectRoot . '/repository');
            if ($this->shellExec("git show-ref refs/heads/$machineName")) {
                // The Git branch already exists locally, so check it out.
                $command = "git checkout $machineName";
                $error = "Failed to checkout branch locally: $machineName";
            }
            else {
                // Create a new branch, using the current or specified
                // environment as the parent.
                $parent = $this->environment['id'];
                $command = "git checkout --quiet $parent; git branch --quiet $machineName";
                $error = "Failed to create branch locally: $machineName";
            }
            $returnVar = '';
            exec($command, $null, $returnVar);
            if ($returnVar > 0) {
                $output->writeln("<error>$error</error>");
                if (!$force) {
                    return 1;
                }
                $local_error = true;
            }
            chdir($cwd);
        }
        elseif ($force) {
            $output->writeln('<comment>Because this command was run from outside your local project root, the new Platform.sh branch could not be checked out in your local Git repository. Make sure to run platform checkout or git checkout in your repository directory to switch to the branch you are expecting.</comment>');
            $local_error = true;
        }
        else {
            $output->writeln("<error>You must run this command inside the project root, or specify --force.</error>");
            return 1;
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $client->branchEnvironment(array('name' => $machineName, 'title' => $branchName));
        // Reload the stored environments, to trigger a drush alias rebuild.
        $this->getEnvironments($this->project);

        $output->writeln("The environment <info>$branchName</info> has been branched.");

        $build = $input->getOption('build');
        if (empty($local_error) && $build && $projectRoot) {
            // Build the new branch.
            $application = $this->getApplication();
            try {
                $buildCommand = $application->find('build');
                $buildCommand->build($projectRoot, $machineName);
            } catch (\Exception $e) {
                $output->writeln("<comment>The new branch could not be built: \n" . $e->getMessage() . "</comment>");
                return 1;
            }
        }

        return 0;
    }
}
