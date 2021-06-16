<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Environment;
use Stecman\Component\Symfony\Console\BashCompletion\Completion\CompletionAwareInterface;
use Stecman\Component\Symfony\Console\BashCompletion\CompletionContext;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentListCommand extends CommandBase implements CompletionAwareInterface
{

    protected $children = [];

    /** @var Environment */
    protected $currentEnvironment;
    protected $mapping = [];

    /** @var \Platformsh\Cli\Service\PropertyFormatter */
    protected $formatter;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('environment:list')
            ->setAliases(['environments', 'env'])
            ->setDescription('Get a list of environments')
            ->addOption('no-inactive', 'I', InputOption::VALUE_NONE, 'Do not show inactive environments')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output a simple list of environment IDs.')
            ->addOption('refresh', null, InputOption::VALUE_REQUIRED, 'Whether to refresh the list.', 1)
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'A property to sort by', 'title')
            ->addOption('reverse', null, InputOption::VALUE_NONE, 'Sort in reverse (descending) order')
            ->addOption('type', null, InputOption::VALUE_REQUIRED|InputOption::VALUE_IS_ARRAY, 'Filter the list by environment type(s)');
        Table::configureInput($this->getDefinition());
        $this->addProjectOption();
    }

    /**
     * Build a tree out of a list of environments.
     *
     * @param Environment[] $environments The list of environments, keyed by ID.
     * @param string|null   $parent       The parent environment for which to
     *                                    build a tree.
     *
     * @return Environment[] A list of the children of $parent, keyed by ID.
     *                       Children of all environments are stored in the
     *                       property $this->children.
     */
    protected function buildEnvironmentTree(array $environments, $parent = null)
    {
        $children = [];
        foreach ($environments as $environment) {
            // Root nodes are both the environments whose parent is null, and
            // environments whose parent does not exist.
            if ($environment->parent === $parent
                || ($parent === null && !isset($environments[$environment->parent]))) {
                $this->children[$environment->id] = $this->buildEnvironmentTree(
                    $environments,
                    $environment->id
                );
                $children[$environment->id] = $environment;
            }
        }

        return $children;
    }

    /**
     * Recursively build rows of the environment table.
     *
     * @param Environment[] $tree
     * @param bool $indent
     * @param int $indentAmount
     * @param bool $indicateCurrent
     *
     * @return array
     */
    protected function buildEnvironmentRows(array $tree, $indent = true, $indicateCurrent = true, $indentAmount = 0)
    {
        $rows = [];
        foreach ($tree as $environment) {
            $row = [];

            // Format the environment ID.
            $id = $environment->id;
            if ($indent) {
                $id = str_repeat('  ', $indentAmount) . $id;
            }

            // Add an indicator for the current environment.
            $cellOptions = [];
            if ($indicateCurrent && $this->currentEnvironment && $environment->id == $this->currentEnvironment->id) {
                $id .= '<info>*</info>';

                // Prevent table cell wrapping so formatting is not broken.
                $cellOptions['wrap'] = false;
            }

            $row[] = new AdaptiveTableCell($id, $cellOptions);

            $row['machine_name'] = $environment->machine_name;

            if ($branch = array_search($environment->id, $this->mapping)) {
                $row[] = sprintf('%s (%s)', $environment->title, $branch);
            } else {
                $row[] = $environment->title;
            }

            $row[] = $this->formatEnvironmentStatus($environment->status);
            $row[] = $environment->getProperty('type', false) ?: '';

            $row[] = $this->formatter->format($environment->created_at, 'created_at');
            $row[] = $this->formatter->format($environment->updated_at, 'updated_at');

            $rows[] = $row;
            if (isset($this->children[$environment->id])) {
                $childRows = $this->buildEnvironmentRows(
                    $this->children[$environment->id],
                    $indent,
                    $indicateCurrent,
                    $indentAmount + 1
                );
                $rows = array_merge($rows, $childRows);
            }
        }

        return $rows;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $refresh = $input->hasOption('refresh') && $input->getOption('refresh');

        $progress = new ProgressMessage($output);
        $progress->showIfOutputDecorated('Loading environments...');

        $project = $this->getSelectedProject();
        $environments = $this->api()->getEnvironments($project, $refresh ? true : null);

        $progress->done();

        // Determine whether environment types are supported.
        $supportsTypes = $project->operationAvailable('environment-types');
        if (!$supportsTypes) {
            $first = reset($environments);
            if ($first && $first->hasProperty('type')) {
                $supportsTypes = true;
            }
        }

        // Filter the list of environments.
        $filters = [];
        if ($input->getOption('no-inactive')) {
            $filters['no-inactive'] = true;
        }
        if ($types = $input->getOption('type')) {
            if (!$supportsTypes) {
                $this->stdErr->writeln('<options=reverse>Warning:</> environment types are not yet supported on this project.');
            }
            if (count($types) === 1) {
                // Split comma- or whitespace-separated values.
                $types = preg_split('/[\s,]+/', reset($types));
            }
            $filters['type'] = $types;
        }
        $this->filterEnvironments($environments, $filters);

        if ($input->getOption('sort')) {
            $this->api()->sortResources($environments, $input->getOption('sort'));
        }
        if ($input->getOption('reverse')) {
            $environments = array_reverse($environments, true);
        }

        if ($input->getOption('pipe')) {
            $output->writeln(array_keys($environments));

            return 0;
        }

        // Display a message if no environments are found.
        if (empty($environments)) {
            if (!empty($filters)) {
                $filtersUsed = '<comment>--'
                    . implode('</comment>, <comment>--', array_keys($filters))
                    . '</comment>';
                $this->stdErr->writeln('No environments found (filters in use: ' . $filtersUsed . ').');
            } else {
                $this->stdErr->writeln(
                    'No environments found.'
                );
            }

            return 0;
        }

        $project = $this->getSelectedProject();
        $this->currentEnvironment = $this->getCurrentEnvironment($project);

        if (($currentProject = $this->getCurrentProject()) && $currentProject == $project) {
            /** @var \Platformsh\Cli\Local\LocalProject $localProject */
            $localProject = $this->getService('local.project');
            $projectConfig = $localProject->getProjectConfig($this->getProjectRoot());
            if (isset($projectConfig['mapping'])) {
                $this->mapping = $projectConfig['mapping'];
            }
        }

        $tree = $this->buildEnvironmentTree($environments);

        $headers = ['ID', 'machine_name' => 'Machine name', 'Title', 'Status', 'Type', 'Created', 'Updated'];
        $defaultColumns = ['id', 'title', 'status'];

        if ($supportsTypes) {
            $defaultColumns[] = 'type';
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $this->formatter = $this->getService('property_formatter');

        if ($table->formatIsMachineReadable()) {
            $table->render($this->buildEnvironmentRows($tree, false, false), $headers, $defaultColumns);
            return 0;
        }

        $this->stdErr->writeln("Your environments are: ");

        $table->render($this->buildEnvironmentRows($tree), $headers, $defaultColumns);

        if (!$this->currentEnvironment) {
            return 0;
        }

        $this->stdErr->writeln("<info>*</info> - Indicates the current environment\n");

        $currentEnvironment = $this->currentEnvironment;
        $executable = $this->config()->get('application.executable');

        $this->stdErr->writeln(
            'Check out a different environment by running <info>' . $executable . ' checkout [id]</info>'
        );

        if ($currentEnvironment->operationAvailable('branch')) {
            $this->stdErr->writeln(
                'Branch a new environment by running <info>' . $executable . ' environment:branch [new-name]</info>'
            );
        }
        if ($currentEnvironment->operationAvailable('activate')) {
            $this->stdErr->writeln(
                'Activate the current environment by running <info>' . $executable . ' environment:activate</info>'
            );
        }
        if ($currentEnvironment->operationAvailable('delete')) {
            $this->stdErr->writeln(
                'Delete the current environment by running <info>' . $executable . ' environment:delete</info>'
            );
        }
        if ($currentEnvironment->operationAvailable('backup')) {
            $this->stdErr->writeln(
                'Make a backup of the current environment by running <info>' . $executable . ' backup</info>'
            );
        }
        if ($currentEnvironment->operationAvailable('merge')) {
            $this->stdErr->writeln(
                'Merge the current environment by running <info>' . $executable . ' environment:merge</info>'
            );
        }
        if ($currentEnvironment->operationAvailable('synchronize')) {
            $this->stdErr->writeln(
                'Sync the current environment by running <info>' . $executable . ' environment:synchronize</info>'
            );
        }

        return 0;
    }

    /**
     * @param string $status
     *
     * @return string
     */
    protected function formatEnvironmentStatus($status)
    {
        if ($status == 'dirty') {
            $status = 'In progress';
        }

        return ucfirst($status);
    }

    /**
     * Filter the list of environments.
     *
     * @param Environment[] &$environments
     * @param mixed[string] $filters
     */
    protected function filterEnvironments(array &$environments, array $filters)
    {
        if (!empty($filters['no-inactive'])) {
            $environments = array_filter($environments, function ($environment) {
                return $environment->status !== 'inactive';
            });
        }
        if (!empty($filters['type'])) {
            $environments = array_filter($environments, function ($environment) use ($filters) {
                return !$environment->hasProperty('type') || \in_array($environment->getProperty('type'), $filters['type']);
            });
        }
    }

    /**
     * {@inheritDoc}
     */
    public function completeOptionValues($optionName, CompletionContext $context)
    {
        if ($optionName === 'type') {
            // @todo fetch types from the project if known? not necessary until custom types are available
            return ['development', 'staging', 'production'];
        }
        if ($optionName === 'sort') {
            return ['id', 'title', 'status', 'name', 'machine_name', 'parent', 'created_at', 'updated_at'];
        }
        return [];
    }

    /**
     * {@inheritDoc}
     */
    public function completeArgumentValues($argumentName, CompletionContext $context)
    {
        return [];
    }
}
