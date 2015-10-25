<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\PlatformCommand;
use Platformsh\Cli\Helper\GitHelper;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Util\ActivityUtil;
use Platformsh\Client\Model\Environment;
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
            'build',
            null,
            InputOption::VALUE_NONE,
            "Build the new environment locally"
          );
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addNoWaitOption("Do not wait for the environment to be branched");
        $this->addExample('Create a new branch "sprint-2", based on "develop"', 'sprint-2 develop');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->envArgName = 'parent';
        $this->validateInput($input, true);
        $selectedProject = $this->getSelectedProject();
        $parentEnvironment = $this->getSelectedEnvironment();

        $branchName = $input->getArgument('name');
        if (empty($branchName)) {
            if ($input->isInteractive()) {
                // List environments.
                return $this->runOtherCommand(
                  'environments',
                  array('--project' => $selectedProject->id)
                );
            }
            $this->stdErr->writeln("<error>You must specify the name of the new branch.</error>");

            return 1;
        }

        $machineName = Environment::sanitizeId($branchName);

        if ($machineName == $parentEnvironment->id) {
            $this->stdErr->writeln("<comment>Already on $machineName</comment>");

            return 1;
        }

        if ($environment = $this->getEnvironment($machineName, $selectedProject)) {
            $checkout = $this->getHelper('question')
                             ->confirm(
                               "The environment <comment>$machineName</comment> already exists. Check out?",
                               $input,
                               $this->stdErr
                             );
            if ($checkout) {
                return $this->runOtherCommand(
                  'environment:checkout',
                  array('id' => $environment->id)
                );
            }

            return 1;
        }

        if (!$parentEnvironment->operationAvailable('branch')) {
            $this->stdErr->writeln(
              "Operation not available: The environment <error>{$parentEnvironment->id}</error> can't be branched."
            );
            if ($parentEnvironment->is_dirty) {
                $this->clearEnvironmentsCache();
            }

            return 1;
        }

        $force = $input->getOption('force');

        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot && $force) {
            $this->stdErr->writeln(
              "<comment>This command was run from outside your local project root, the new Platform.sh branch cannot be checked out in your local Git repository."
              . " Make sure to run 'platform checkout' or 'git checkout' in your repository directory to switch to the branch you are expecting.</comment>"
            );
            $local_error = true;
        } elseif (!$projectRoot) {
            $this->stdErr->writeln("<error>You must run this command inside the project root, or specify --force.</error>");

            return 1;
        }

        $this->stdErr->writeln(
          "Creating a new environment <info>$branchName</info>, branched from <info>{$parentEnvironment->title}</info>"
        );

        $activity = $parentEnvironment->branch($branchName, $machineName);

        // Clear the environments cache, as branching has started.
        $this->clearEnvironmentsCache($selectedProject);

        if ($projectRoot) {
            $gitHelper = new GitHelper(new ShellHelper($this->stdErr));
            $gitHelper->setDefaultRepositoryDir($projectRoot . '/' . LocalProject::REPOSITORY_DIR);

            // If the Git branch already exists locally, just check it out.
            $existsLocally = $gitHelper->branchExists($machineName);
            if ($existsLocally) {
                $this->stdErr->writeln("Checking out <info>$machineName</info> locally");
                if (!$gitHelper->checkOut($machineName)) {
                    $this->stdErr->writeln('<error>Failed to check out branch locally: ' . $machineName . '</error>');
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
                $this->stdErr->writeln("Creating local branch <info>$machineName</info>");
                if (!$gitHelper->checkOutNew($machineName, $parent)) {
                    $this->stdErr->writeln('<error>Failed to create branch locally: ' . $machineName . '</error>');
                    $local_error = true;
                    if (!$force) {
                        return 1;
                    }
                }
            }
        }

        $remoteSuccess = true;
        if (!$input->getOption('no-wait')) {
            $this->stdErr->writeln('Waiting for the environment to be branched...');
            $remoteSuccess = ActivityUtil::waitAndLog(
              $activity,
              $this->stdErr,
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
                $this->stdErr->writeln("<comment>The new branch could not be built: \n" . $e->getMessage() . "</comment>");

                return 1;
            }
        }

        $this->clearEnvironmentsCache();

        return $remoteSuccess ? 0 : 1;
    }
}
