<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Helper\GitHelper;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Cli\Local\LocalProject;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentSetRemoteCommand extends CommandBase
{

    protected function configure()
    {
        $this
          ->setName('environment:set-remote')
          ->setDescription('Set the remote environment to track for a branch')
          ->addArgument(
            'environment',
            InputArgument::REQUIRED,
            'The environment machine name. Set to 0 to stop tracking a branch'
          )
          ->addArgument(
            'branch',
            InputArgument::OPTIONAL,
            'The Git branch to track (defaults to the current branch)'
          );
        $this->addExample('Set the remote environment for this branch to "pr-655"', 'pr-655');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->getCurrentProject();
        if (!$project) {
            throw new RootNotFoundException();
        }

        $projectRoot = $this->getProjectRoot();
        $repositoryDir = $projectRoot . '/' . LocalProject::REPOSITORY_DIR;

        $gitHelper = new GitHelper(new ShellHelper($output));
        $gitHelper->setDefaultRepositoryDir($repositoryDir);

        $specifiedEnvironmentId = $input->getArgument('environment');
        if ($specifiedEnvironmentId != '0' && !$specifiedEnvironment = $this->getEnvironment($specifiedEnvironmentId, $project)) {
            $this->stdErr->writeln("Environment not found: <error>$specifiedEnvironmentId</error>");
            return 1;
        }

        $specifiedBranch = $input->getArgument('branch');
        if ($specifiedBranch) {
            if (!$gitHelper->branchExists($specifiedBranch)) {
                $this->stdErr->writeln("Branch not found: <error>$specifiedBranch</error>");
                return 1;
            }
        }
        else {
            $specifiedBranch = $gitHelper->getCurrentBranch();
        }

        // Check whether the branch is mapped by default (its name or its Git
        // upstream is the same as the remote environment ID).
        $mappedByDefault = ($specifiedEnvironmentId === $specifiedBranch);
        if ($specifiedEnvironmentId != '0' && !$mappedByDefault) {
            $upstream = $gitHelper->getUpstream($specifiedBranch);
            if (strpos($upstream, '/')) {
                list(, $upstream) = explode('/', $upstream, 2);
            }
            if ($upstream === $specifiedEnvironmentId) {
                $mappedByDefault = true;
            }
            if (!$mappedByDefault && $gitHelper->branchExists($specifiedEnvironmentId)) {
                $this->stdErr->writeln("A local branch already exists named <comment>$specifiedEnvironmentId</comment>");
            }
        }

        // Perform the mapping or unmapping.
        $config = $this->getProjectConfig($projectRoot);
        $config += ['mapping' => []];
        if ($mappedByDefault || $specifiedEnvironmentId == '0') {
            unset($config['mapping'][$specifiedBranch]);
            $this->setProjectConfig('mapping', $config['mapping'], $projectRoot);
        }
        elseif (!$this->getEnvironment($specifiedBranch)) {
            if (isset($config['mapping']) && ($current = array_search($specifiedEnvironmentId, $config['mapping'])) !== false) {
                unset($config['mapping'][$current]);
            }
            $config['mapping'][$specifiedBranch] = $specifiedEnvironmentId;
            $this->setProjectConfig('mapping', $config['mapping'], $projectRoot);
        }

        // Check the success of the operation.
        if (isset($config['mapping'][$specifiedBranch])) {
            $actualRemoteEnvironment = $config['mapping'][$specifiedBranch];
            $this->stdErr->writeln("The local branch <info>$specifiedBranch</info> is tracking the remote environment <info>$actualRemoteEnvironment</info>");
        }
        elseif ($mappedByDefault || $this->getEnvironment($specifiedBranch)) {
            $actualRemoteEnvironment = $specifiedBranch;
            $this->stdErr->writeln("The local branch <info>$specifiedBranch</info> is tracking the default remote environment, <info>$specifiedBranch</info>");
        }
        else {
            $this->stdErr->writeln("The local branch <info>$specifiedBranch</info> is not tracking a remote environment");
        }

        $success = !empty($actualRemoteEnvironment)
          ? $actualRemoteEnvironment == $specifiedEnvironmentId
          : $specifiedEnvironmentId == '0';

        return $success ? 0 : 1;
    }
}
