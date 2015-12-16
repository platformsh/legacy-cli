<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\Table;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentListCommand extends CommandBase
{

    protected $children = [];

    /** @var Environment */
    protected $currentEnvironment;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('environment:list')
            ->setAliases(['environments'])
            ->setDescription('Get a list of environments')
            ->addOption(
                'no-inactive',
                'I',
                InputOption::VALUE_NONE,
                'Do not show inactive environments'
            )
            ->addOption(
                'pipe',
                null,
                InputOption::VALUE_NONE,
                'Output a simple list of environment IDs.'
            )
            ->addOption(
                'refresh',
                null,
                InputOption::VALUE_REQUIRED,
                'Whether to refresh the list.',
                1
            );
        Table::addFormatOption($this->getDefinition());
        $this->addProjectOption();
    }

    /**
     * Build a tree out of a list of environments.
     *
     * @param Environment[] $environments
     * @param string        $parent
     *
     * @return array
     */
    protected function buildEnvironmentTree(array $environments, $parent = null)
    {
        $children = [];
        foreach ($environments as $environment) {
            if ($environment->parent === $parent) {
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
    protected function buildEnvironmentRows($tree, $indent = true, $indicateCurrent = true, $indentAmount = 0)
    {
        $rows = [];
        foreach ($tree as $environment) {
            $row = [];

            $id = $environment->id;
            if ($indent) {
                $id = str_repeat('   ', $indentAmount) . $id;
            }
            if ($indicateCurrent && $environment->id == $this->currentEnvironment->id) {
                $id .= "<info>*</info>";
            }
            $row[] = $id;
            $row[] = $environment->title;
            $row[] = $this->formatEnvironmentStatus($environment->status);
            $rows[] = $row;
            $rows = array_merge($rows, $this->buildEnvironmentRows($this->children[$environment->id], $indent, $indicateCurrent, $indentAmount + 1));
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

        $environments = $this->getEnvironments(null, $refresh);

        if ($input->getOption('no-inactive')) {
            $environments = array_filter($environments, function ($environment) {
                return $environment->status !== 'inactive';
            });
        }

        if ($input->getOption('pipe')) {
            $output->writeln(array_keys($environments));

            return;
        }

        $project = $this->getSelectedProject();
        $this->currentEnvironment = $this->getCurrentEnvironment($project);

        $tree = $this->buildEnvironmentTree($environments);

        // To make the display nicer, we move all the children of master
        // to the top level.
        if (isset($tree['master'])) {
            $tree += $this->children['master'];
            $this->children['master'] = [];
        }

        $headers = ['ID', 'Name', 'Status'];

        $table = new Table($input, $output);

        if ($table->formatIsMachineReadable()) {
            $table->render($this->buildEnvironmentRows($tree, false, false), $headers);

            return;
        }

        $this->stdErr->writeln("Your environments are: ");

        $table->render($this->buildEnvironmentRows($tree), $headers);

        if (!$this->currentEnvironment) {
            return;
        }

        $this->stdErr->writeln("<info>*</info> - Indicates the current environment\n");

        $currentEnvironment = $this->currentEnvironment;

        $this->stdErr->writeln("Check out a different environment by running <info>platform checkout [id]</info>");

        if ($currentEnvironment->operationAvailable('branch')) {
            $this->stdErr->writeln(
                "Branch a new environment by running <info>platform environment:branch [new-name]</info>"
            );
        }
        if ($currentEnvironment->operationAvailable('activate')) {
            $this->stdErr->writeln(
                "Activate the current environment by running <info>platform environment:activate</info>"
            );
        }
        if ($currentEnvironment->operationAvailable('delete')) {
            $this->stdErr->writeln("Delete the current environment by running <info>platform environment:delete</info>");
        }
        if ($currentEnvironment->operationAvailable('backup')) {
            $this->stdErr->writeln(
                "Make a snapshot of the current environment by running <info>platform snapshot:create</info>"
            );
        }
        if ($currentEnvironment->operationAvailable('merge')) {
            $this->stdErr->writeln("Merge the current environment by running <info>platform environment:merge</info>");
        }
        if ($currentEnvironment->operationAvailable('synchronize')) {
            $this->stdErr->writeln(
                "Sync the current environment by running <info>platform environment:synchronize</info>"
            );
        }
    }

    /**
     * @param string $status
     *
     * @return string
     */
    protected function formatEnvironmentStatus($status) {
        if ($status == 'dirty') {
            $status = 'In progress';
        }

        return ucfirst($status);
    }
}
