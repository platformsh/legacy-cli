<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Helper\GitHelper;
use CommerceGuys\Platform\Cli\Helper\ShellHelper;
use CommerceGuys\Platform\Cli\Local\LocalProject;
use CommerceGuys\Platform\Cli\Model\Activity;
use CommerceGuys\Platform\Cli\Model\Environment;
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
            ->addArgument('parent', InputArgument::OPTIONAL, 'The parent of the new environment')
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
        $this->addProjectOption()
          ->addEnvironmentOption()
          ->addNoWaitOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->envArgName = 'parent';
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

        $machineName = Environment::sanitizeId($branchName);
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

        if (!$this->operationAvailable('branch')) {
            $output->writeln("Operation not available: The environment <error>$environmentId</error> can't be branched.");
            return 1;
        }

        $force = $input->getOption('force');

        $projectRoot = $this->getProjectRoot();
        if ($projectRoot) {
            $gitHelper = new GitHelper(new ShellHelper($output));
            $gitHelper->setDefaultRepositoryDir($projectRoot . '/' . LocalProject::REPOSITORY_DIR);
            // If the Git branch already exists locally, check it out.
            $existsLocally = $gitHelper->branchExists($machineName);
            if ($existsLocally) {
                $output->writeln("Checking out <info>$machineName</info>");
                if (!$gitHelper->checkOut($machineName)) {
                    $output->writeln('<error>Failed to check out branch locally: ' . $machineName . '</error>');
                    $local_error = true;
                    if (!$force) {
                        return 1;
                    }
                }
            }
            else {
                // Create a new branch, using the current or specified environment as the parent.
                $parent = $this->environment['id'];
                $output->writeln("Creating local branch <info>$machineName</info>");
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

        $parentTitle = $this->environment['title'];
        $output->writeln("Creating a new environment <info>$branchName</info>, branched from <info>$parentTitle</info>");

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $response = $client->branchEnvironment(array('name' => $machineName, 'title' => $branchName));
        $success = true;
        if (!$input->getOption('no-wait')) {
            $success = Activity::waitAndLog(
              $response,
              $client,
              $output,
              "The environment <info>$branchName</info> has been branched.",
              '<error>Branching failed</error>'
            );
        }

        // Reload the stored environments.
        $this->getEnvironments($this->project, true);

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

        return $success !== false ? 0 : 1;
    }
}
