<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Helper\GitHelper;
use Platformsh\Cli\Helper\ShellHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentSetRemoteCommand extends CommandBase
{
    // @todo remove this command in v3
    protected $hiddenInList = true;

    protected function configure()
    {
        $this
            ->setName('environment:set-remote')
            ->setDescription('Set the remote environment to map to a branch')
            ->addArgument(
                'environment',
                InputArgument::REQUIRED,
                'The environment machine name. Set to 0 to remove the mapping for a branch'
            )
            ->addArgument(
                'branch',
                InputArgument::OPTIONAL,
                'The Git branch to map (defaults to the current branch)'
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

        $gitHelper = new GitHelper(new ShellHelper($output));
        $gitHelper->setDefaultRepositoryDir($projectRoot);

        $specifiedEnvironmentId = $input->getArgument('environment');
        if ($specifiedEnvironmentId != '0' && !$specifiedEnvironment = $this->api()->getEnvironment($specifiedEnvironmentId, $project)) {
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
        $mappedByDefault = isset($specifiedEnvironment) && $specifiedEnvironment->status != 'inactive' && $specifiedEnvironmentId === $specifiedBranch;
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
        $projectConfig = $this->localProject->getProjectConfig($projectRoot);
        $projectConfig += ['mapping' => []];
        if ($mappedByDefault || $specifiedEnvironmentId == '0') {
            unset($projectConfig['mapping'][$specifiedBranch]);
            $this->localProject->writeCurrentProjectConfig($projectConfig, $projectRoot);
        }
        else {
            if (isset($projectConfig['mapping']) && ($current = array_search($specifiedEnvironmentId, $projectConfig['mapping'])) !== false) {
                unset($projectConfig['mapping'][$current]);
            }
            $projectConfig['mapping'][$specifiedBranch] = $specifiedEnvironmentId;
            $this->localProject->writeCurrentProjectConfig($projectConfig, $projectRoot);
        }

        // Check the success of the operation.
        if (isset($projectConfig['mapping'][$specifiedBranch])) {
            $actualRemoteEnvironment = $projectConfig['mapping'][$specifiedBranch];
            $this->stdErr->writeln("The local branch <info>$specifiedBranch</info> is mapped to the remote environment <info>$actualRemoteEnvironment</info>");
        }
        elseif ($mappedByDefault) {
            $actualRemoteEnvironment = $specifiedBranch;
            $this->stdErr->writeln("The local branch <info>$specifiedBranch</info> is mapped to the default remote environment, <info>$specifiedBranch</info>");
        }
        else {
            $this->stdErr->writeln("The local branch <info>$specifiedBranch</info> is not mapped to a remote environment");
        }

        $success = !empty($actualRemoteEnvironment)
            ? $actualRemoteEnvironment == $specifiedEnvironmentId
            : $specifiedEnvironmentId == '0';

        return $success ? 0 : 1;
    }
}
