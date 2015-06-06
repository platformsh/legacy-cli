<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Helper\GitHelper;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Util\ActivityUtil;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentBranchCommand extends PlatformCommand
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
            'no-wait',
            null,
            InputOption::VALUE_NONE,
            'Do not wait for the Platform.sh branch to be created'
          )
          ->addOption(
            'build',
            null,
            InputOption::VALUE_NONE,
            "Build the new environment locally"
          );
        $this->addProjectOption()
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->envArgName = 'parent';
        $this->validateInput($input, $output, true);

        $branchName = $input->getArgument('name');
        if (empty($branchName)) {
            if ($input->isInteractive()) {
                // List environments.
                $params = array(
                  'command' => 'environments',
                  '--project' => $this->getSelectedProject()['id'],
                );

                return $this->getApplication()
                            ->find('environments')
                            ->run(new ArrayInput($params), $output);
            }
            $output->writeln("<error>You must specify the name of the new branch.</error>");

            return 1;
        }

        $machineName = Environment::sanitizeId($branchName);
        $environmentId = $this->getSelectedEnvironment()['id'];

        if ($machineName == $environmentId) {
            $output->writeln("<comment>Already on $machineName</comment>");

            return 1;
        }

        if ($environment = $this->getEnvironment($machineName, $this->getSelectedProject())) {
            $checkout = $this->getHelper('question')
                             ->confirm(
                               "The environment <comment>$machineName</comment> already exists. Check out?",
                               $input,
                               $output
                             );
            if ($checkout) {
                $checkoutCommand = $this->getApplication()
                                        ->find('environment:checkout');
                $checkoutInput = new ArrayInput(
                  array(
                    'command' => 'environment:checkout',
                    'id' => $environment['id'],
                  )
                );

                return $checkoutCommand->run($checkoutInput, $output);
            }

            return 1;
        }

        if (!$this->getSelectedEnvironment()
                  ->operationAvailable('branch')
        ) {
            $output->writeln(
              "Operation not available: The environment <error>$environmentId</error> can't be branched."
            );

            return 1;
        }

        $force = $input->getOption('force');

        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot && $force) {
            $output->writeln(
              "<comment>This command was run from outside your local project root, the new Platform.sh branch cannot be checked out in your local Git repository."
              . " Make sure to run 'platform checkout' or 'git checkout' in your repository directory to switch to the branch you are expecting.</comment>"
            );
            $local_error = true;
        } elseif (!$projectRoot) {
            $output->writeln("<error>You must run this command inside the project root, or specify --force.</error>");

            return 1;
        }

        $selectedEnvironment = $this->getSelectedEnvironment();

        $output->writeln(
          "Creating a new environment <info>$branchName</info>, branched from <info>{$selectedEnvironment['title']}</info>"
        );

        $activity = $selectedEnvironment->branch($branchName, $machineName);

        if ($projectRoot) {
            $gitHelper = new GitHelper(new ShellHelper($output));
            $gitHelper->setDefaultRepositoryDir($projectRoot . '/' . LocalProject::REPOSITORY_DIR);

            $output->writeln("Checking out <info>$machineName</info> locally");

            // If the Git branch already exists locally, just check it out.
            $existsLocally = $gitHelper->branchExists($machineName);
            if ($existsLocally) {
                if (!$gitHelper->checkOut($machineName)) {
                    $output->writeln('<error>Failed to check out branch locally: ' . $machineName . '</error>');
                    $local_error = true;
                    if (!$force) {
                        return 1;
                    }
                }
            } else {
                // Create a new branch, using the current or specified environment as the parent if it exists locally.
                $parent = $this->getSelectedEnvironment()['id'];
                if (!$gitHelper->branchExists($parent)) {
                    $parent = null;
                }
                $output->writeln("Creating local branch out <info>$machineName</info>");
                if (!$gitHelper->checkOutNew($machineName, $parent)) {
                    $output->writeln('<error>Failed to create branch locally: ' . $machineName . '</error>');
                    $local_error = true;
                    if (!$force) {
                        return 1;
                    }
                }
            }
        }

        $remoteSuccess = true;
        if (!$input->getOption('no-wait')) {
            $remoteSuccess = ActivityUtil::waitAndLog(
              $activity,
              $output,
              "The environment <info>$branchName</info> has been branched.",
              '<error>Branching failed</error>'
            );
        }

        $build = $input->getOption('build');
        if (empty($local_error) && $build && $projectRoot) {
            // Build the new branch.
            try {
                $buildSettings = array(
                  'environmentId' => $machineName,
                  'verbosity' => $output->getVerbosity(),
                );
                $builder = new LocalBuild($buildSettings, $output);
                $builder->buildProject($projectRoot);
            } catch (\Exception $e) {
                $output->writeln("<comment>The new branch could not be built: \n" . $e->getMessage() . "</comment>");

                return 1;
            }
        }

        // Refresh the environments cache.
        $this->getEnvironments(null, true);

        return $remoteSuccess ? 0 : 1;
    }
}
