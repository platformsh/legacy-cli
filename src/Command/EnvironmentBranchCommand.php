<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\ArrayInput;
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
            ->setDescription('Branch an environment')
            ->addArgument(
                'name',
                InputArgument::OPTIONAL,
                'The name of the new environment. For example: "Sprint 2"'
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                "Create the new environment even if the branch cannot be checked out locally"
            )
            ->addOption(
                'build',
                null,
                InputOption::VALUE_NONE,
                "Build the new environment locally"
            );
        $this->addProjectOption()->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $branchName = $input->getArgument('name');
        if (empty($branchName)) {
            if ($input->isInteractive()) {
                // List environments.
                $params = array(
                    'command' => 'environments',
                    '--project' => $this->project['id'],
                );
                return $this->getApplication()
                    ->find('environments')
                    ->run(new ArrayInput($params), $output);
            }
            $output->writeln("<error>You must specify the name of the new branch.</error>");
            return 1;
        }

        $machineName = $this->sanitizeEnvironmentId($branchName);
        $environmentId = $this->environment['id'];

        if ($machineName == $environmentId) {
            $output->writeln("<comment>Already on $machineName</comment>");
            return 1;
        }

        if ($environment = $this->getEnvironment($machineName, $this->project)) {
            $checkout = $this->getHelper('question')->confirm("<comment>The environment $machineName already exists.</comment> Check out?", $input, $output);
            if ($checkout) {
                $checkoutCommand = $this->getApplication()->find('environment:checkout');
                $checkoutInput = new ArrayInput(array(
                      'command' => 'environment:checkout',
                      'id' => $environment['id'],
                  ));
                return $checkoutCommand->run($checkoutInput, $output);
            }
            return 1;
        }

        if (!$this->operationAllowed('branch')) {
            $output->writeln("Operation not permitted: The environment <error>$environmentId</error> can't be branched.");
            return 1;
        }

        $force = $input->getOption('force');

        $projectRoot = $this->getProjectRoot();
        if ($projectRoot) {
            $gitHelper = $this->getHelper('git');
            $gitHelper->setDefaultRepositoryDir($projectRoot . '/repository');
            // If the Git branch already exists locally, check it out.
            $existsLocally = $gitHelper->branchExists($machineName);
            if ($existsLocally && !$gitHelper->checkOut($machineName)) {
                $output->writeln('<error>Failed to check out branch locally: ' . $machineName . '</error>');
                $local_error = true;
                if (!$force) {
                    return 1;
                }
            }
            elseif (!$existsLocally) {
                // Create a new branch, using the current or specified environment as the parent.
                $parent = $this->environment['id'];
                if (!$gitHelper->checkOutNew($machineName, $parent)) {
                    $output->writeln('<error>Failed to create branch locally: ' . $machineName . '</error>');
                    $local_error = true;
                    if (!$force) {
                        return 1;
                    }
                }
            }
        }
        elseif ($force) {
            $output->writeln("<comment>Because this command was run from outside your local project root, the new Platform.sh branch could not be checked out in your local Git repository."
                . " Make sure to run 'platform checkout' or 'git checkout' in your repository directory to switch to the branch you are expecting.</comment>");
            $local_error = true;
        }
        else {
            $output->writeln("<error>You must run this command inside the project root, or specify --force.</error>");
            return 1;
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $client->branchEnvironment(array('name' => $machineName, 'title' => $branchName));
        // Reload the stored environments.
        $this->getEnvironments($this->project, true);

        $output->writeln("The environment <info>$branchName</info> has been branched.");

        $build = $input->getOption('build');
        if (empty($local_error) && $build && $projectRoot) {
            // Build the new branch.
            $application = $this->getApplication();
            try {
                $buildCommand = $application->find('build');
                $buildSettings = array(
                    'environmentId' => $machineName,
                    'verbosity' => $output->getVerbosity(),
                );
                $buildCommand->build($projectRoot, $buildSettings, $output);
            } catch (\Exception $e) {
                $output->writeln("<comment>The new branch could not be built: \n" . $e->getMessage() . "</comment>");
                return 1;
            }
        }

        return 0;
    }
}
