<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Util\ActivityUtil;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentDeleteCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('environment:delete')
            ->setDescription('Delete an environment')
            ->addArgument('environment', InputArgument::IS_ARRAY, 'The environment(s) to delete')
            ->addOption('delete-branch', null, InputOption::VALUE_NONE, 'Delete the remote Git branch(es) too')
            ->addOption('no-delete-branch', null, InputOption::VALUE_NONE, 'Do not delete the remote Git branch(es)')
            ->addOption('inactive', null, InputOption::VALUE_NONE, 'Delete all inactive environments')
            ->addOption('merged', null, InputOption::VALUE_NONE, 'Delete all merged environments')
            ->addOption('exclude', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Environments not to delete');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addNoWaitOption();
        $this->addExample('Delete the environments "test" and "example-1"', 'test example-1');
        $this->addExample('Delete all inactive environments', '--inactive');
        $this->addExample('Delete all environments merged with "master"', '--merged master');
        $service = self::$config->get('service.name');
        $this->setHelp(<<<EOF
When a {$service} environment is deleted, it will become "inactive": it will
exist only as a Git branch, containing code but no services, databases nor
files.

This command allows you to delete environment(s) as well as their Git branches.
EOF
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, true);

        $environments = $this->api()->getEnvironments($this->getSelectedProject());

        $toDelete = [];

        // Gather inactive environments.
        if ($input->getOption('inactive')) {
            if ($input->getOption('no-delete-branch')) {
                $this->stdErr->writeln('The option --no-delete-branch cannot be combined with --inactive.');

                return 1;
            }
            $inactive = array_filter(
                $environments,
                function ($environment) {
                    /** @var Environment $environment */
                    return $environment->status == 'inactive';
                }
            );
            if (!$inactive) {
                $this->stdErr->writeln('No inactive environments found.');
            }
            $toDelete = array_merge($toDelete, $inactive);
        }

        // Gather merged environments.
        if ($input->getOption('merged')) {
            if (!$this->hasSelectedEnvironment()) {
                $this->stdErr->writeln('Cannot find merged environments: no base environment specified.');

                return 1;
            }
            $base = $this->getSelectedEnvironment()->id;
            $this->stdErr->writeln("Finding environments merged with <info>$base</info>.");
            $merged = $this->getMergedEnvironments($base);
            if (!$merged) {
                $this->stdErr->writeln('No merged environments found.');
            }
            $toDelete = array_merge($toDelete, $merged);
        }

        // If --merged and --inactive are not specified, look for the selected
        // environment(s).
        if (!$input->getOption('merged') && !$input->getOption('inactive')) {
            if ($this->hasSelectedEnvironment()) {
                $toDelete = [$this->getSelectedEnvironment()];
            } elseif ($environmentIds = $input->getArgument('environment')) {
                $toDelete = array_intersect_key($environments, array_flip($environmentIds));
                $notFound = array_diff($environmentIds, array_keys($environments));
                foreach ($notFound as $notFoundId) {
                    $this->stdErr->writeln("Environment not found: <error>$notFoundId</error>");
                }
            }
        }

        // Exclude environment(s) specified in --exclude.
        $toDelete = array_diff_key($toDelete, array_flip($input->getOption('exclude')));

        if (empty($toDelete)) {
            return 1;
        }

        $success = $this->deleteMultiple($toDelete, $input, $this->stdErr);

        return $success ? 0 : 1;
    }

    /**
     * @param string $base
     *
     * @return array
     */
    protected function getMergedEnvironments($base)
    {
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }

        /** @var \Platformsh\Cli\Helper\GitHelper $gitHelper */
        $gitHelper = $this->getHelper('git');
        $gitHelper->setDefaultRepositoryDir($projectRoot);
        $this->localProject->ensureGitRemote($projectRoot, $this->getSelectedProject()->getGitUrl());

        $remoteName = self::$config->get('detection.git_remote_name');

        // Find a list of branches merged on the remote.
        $gitHelper->fetch($remoteName);
        $mergedBranches = $gitHelper->getMergedBranches($remoteName . '/' . $base, true);
        $mergedBranches = array_filter($mergedBranches, function ($mergedBranch) use ($remoteName, $base) {
            return strpos($mergedBranch, $remoteName) === 0;
        });
        $stripLength = strlen($remoteName . '/');
        $mergedBranches = array_map(function ($mergedBranch) use ($stripLength) {
            return substr($mergedBranch, $stripLength);
        }, $mergedBranches);

        if (empty($mergedBranches)) {
            return [];
        }

        // Reconcile this with the list of environments from the API.
        $environments = $this->api()->getEnvironments($this->getSelectedProject(), true);
        $mergedEnvironments = array_intersect_key($environments, array_flip($mergedBranches));
        unset($mergedEnvironments[$base], $mergedEnvironments['master']);
        $parent = $environments[$base]['parent'];
        if ($parent) {
            unset($mergedEnvironments[$parent]);
        }

        return $mergedEnvironments;
    }

    /**
     * @param Environment[]   $environments
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return bool
     */
    protected function deleteMultiple(array $environments, InputInterface $input, OutputInterface $output)
    {
        // Confirm which environments the user wishes to be deleted.
        $delete = [];
        $deactivate = [];
        $error = false;
        $questionHelper = $this->getHelper('question');
        foreach ($environments as $environment) {
            $environmentId = $environment->id;
            if ($environmentId == 'master') {
                $output->writeln("The <error>master</error> environment cannot be deleted.");
                $error = true;
                continue;
            }
            // Check that the environment does not have children.
            // @todo remove this check when Platform's behavior is fixed
            foreach ($this->api()->getEnvironments($this->getSelectedProject()) as $potentialChild) {
                if ($potentialChild->parent == $environment->id) {
                    $output->writeln(
                        "The environment <error>$environmentId</error> has children and therefore can't be deleted."
                    );
                    $output->writeln("Please delete the environment's children first.");
                    $error = true;
                    continue 2;
                }
            }
            if ($environment->isActive()) {
                $output->writeln("The environment <comment>$environmentId</comment> is currently active: deleting it will delete all associated data.");
                if ($questionHelper->confirm("Are you sure you want to delete the environment <comment>$environmentId</comment>?")) {
                    $deactivate[$environmentId] = $environment;
                    if (!$input->getOption('no-delete-branch')) {
                        if ($input->getOption('delete-branch') || ($input->isInteractive() && $questionHelper->confirm("Delete the remote Git branch too?"))) {
                            $delete[$environmentId] = $environment;
                        }
                    }
                }
            }
            elseif ($environment->status === 'inactive') {
                if ($questionHelper->confirm("Are you sure you want to delete the remote Git branch <comment>$environmentId</comment>?")) {
                    $delete[$environmentId] = $environment;
                }
            }
            elseif ($environment->status === 'dirty') {
                $output->writeln("The environment <error>$environmentId</error> is currently building, and therefore can't be deleted. Please wait.");
                $error = true;
                continue;
            }
        }

        $deactivateActivities = [];
        $deactivated = 0;
        /** @var Environment $environment */
        foreach ($deactivate as $environmentId => $environment) {
            try {
                $output->writeln("Deleting environment <info>$environmentId</info>");
                $deactivateActivities[] = $environment->deactivate();
                $deactivated++;
            } catch (\Exception $e) {
                $output->writeln($e->getMessage());
            }
        }

        if (!$input->getOption('no-wait')) {
            if (!ActivityUtil::waitMultiple($deactivateActivities, $output, $this->getSelectedProject())) {
                $error = true;
            }
        }

        $deleted = 0;
        foreach ($delete as $environmentId => $environment) {
            try {
                if ($environment->status !== 'inactive') {
                    $environment->refresh();
                    if ($environment->status !== 'inactive') {
                        $output->writeln("Cannot delete branch <error>$environmentId</error>: it is not (yet) inactive.");
                        continue;
                    }
                }
                $environment->delete();
                $output->writeln("Deleted remote Git branch <info>$environmentId</info>");
                $deleted++;
            } catch (\Exception $e) {
                $output->writeln($e->getMessage());
            }
        }

        if ($deleted < count($delete) || $deactivated < count($deactivate)) {
            $error = true;
        }

        if (($deleted || $deactivated || $error) && isset($environment)) {
            $this->api()->clearEnvironmentsCache($environment->project);
        }

        return !$error;
    }

}
