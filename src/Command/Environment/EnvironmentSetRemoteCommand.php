<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'environment:set-remote', description: 'Set the remote environment to map to a branch')]
class EnvironmentSetRemoteCommand extends CommandBase
{
    // @todo remove this command in v3
    protected bool $hiddenInList = true;
    public function __construct(private readonly Api $api, private readonly Git $git, private readonly LocalProject $localProject, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'environment',
                InputArgument::REQUIRED,
                'The environment machine name. Set to 0 to remove the mapping for a branch',
            )
            ->addArgument(
                'branch',
                InputArgument::OPTIONAL,
                'The Git branch to map (defaults to the current branch)',
            );
        $this->addExample('Set the remote environment for this branch to "pr-655"', 'pr-655');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $project = $this->selector->getCurrentProject();
        if (!$project) {
            throw new RootNotFoundException();
        }

        $projectRoot = (string) $this->selector->getProjectRoot();
        $this->git->setDefaultRepositoryDir($projectRoot);

        $specifiedEnvironmentId = $input->getArgument('environment');
        $specifiedEnvironment = null;
        if ($specifiedEnvironmentId != '0') {
            $specifiedEnvironment = $this->api->getEnvironment($specifiedEnvironmentId, $project);
            if (!$specifiedEnvironment) {
                $this->stdErr->writeln("Environment not found: <error>$specifiedEnvironmentId</error>");
                return 1;
            }
        }

        $specifiedBranch = $input->getArgument('branch');
        if ($specifiedBranch) {
            if (!$this->git->branchExists($specifiedBranch)) {
                $this->stdErr->writeln("Branch not found: <error>$specifiedBranch</error>");
                return 1;
            }
        } else {
            $specifiedBranch = $this->git->getCurrentBranch();
        }

        // Check whether the branch is mapped by default (its name or its Git
        // upstream is the same as the remote environment ID).
        $mappedByDefault = $specifiedEnvironment
            && $specifiedEnvironment->status != 'inactive'
            && $specifiedEnvironmentId === $specifiedBranch;
        if ($specifiedEnvironmentId != '0' && !$mappedByDefault) {
            $upstream = $this->git->getUpstream($specifiedBranch);
            if (strpos((string) $upstream, '/')) {
                [, $upstream] = explode('/', (string) $upstream, 2);
            }
            if ($upstream === $specifiedEnvironmentId) {
                $mappedByDefault = true;
            }
            if (!$mappedByDefault && $this->git->branchExists($specifiedEnvironmentId)) {
                $this->stdErr->writeln(
                    "A local branch already exists named <comment>$specifiedEnvironmentId</comment>",
                );
            }
        }
        $projectConfig = $this->localProject->getProjectConfig($projectRoot);
        $projectConfig += ['mapping' => []];
        if ($mappedByDefault || $specifiedEnvironmentId == '0') {
            unset($projectConfig['mapping'][$specifiedBranch]);
            $this->localProject->writeCurrentProjectConfig($projectConfig, $projectRoot);
        } else {
            if (isset($projectConfig['mapping'])
                && ($current = array_search($specifiedEnvironmentId, $projectConfig['mapping'])) !== false) {
                unset($projectConfig['mapping'][$current]);
            }
            $projectConfig['mapping'][$specifiedBranch] = $specifiedEnvironmentId;
            $this->localProject->writeCurrentProjectConfig($projectConfig, $projectRoot);
        }

        // Check the success of the operation.
        if (isset($projectConfig['mapping'][$specifiedBranch])) {
            $actualRemoteEnvironment = $projectConfig['mapping'][$specifiedBranch];
            $this->stdErr->writeln(sprintf(
                'The local branch <info>%s</info> is mapped to the remote environment <info>%s</info>',
                $specifiedBranch,
                $actualRemoteEnvironment,
            ));
        } elseif ($mappedByDefault) {
            $actualRemoteEnvironment = $specifiedBranch;
            $this->stdErr->writeln(sprintf(
                'The local branch <info>%s</info> is mapped to the default remote environment, <info>%s</info>',
                $specifiedBranch,
                $actualRemoteEnvironment,
            ));
        } else {
            $this->stdErr->writeln(sprintf(
                'The local branch <info>%s</info> is not mapped to a remote environment',
                $specifiedBranch,
            ));
        }

        $success = !empty($actualRemoteEnvironment)
            ? $actualRemoteEnvironment == $specifiedEnvironmentId
            : $specifiedEnvironmentId == '0';

        return $success ? 0 : 1;
    }
}
