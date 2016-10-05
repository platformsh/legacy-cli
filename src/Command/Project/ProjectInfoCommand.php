<?php
namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Util\ActivityUtil;
use Platformsh\Cli\Util\Table;
use Platformsh\Cli\Util\PropertyFormatter;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectInfoCommand extends CommandBase
{
    /** @var PropertyFormatter */
    protected $formatter;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('project:info')
            ->addArgument('property', InputArgument::OPTIONAL, 'The name of the property')
            ->addArgument('value', InputArgument::OPTIONAL, 'Set a new value for the property')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache')
            ->setDescription('Read or set properties for a project');
        PropertyFormatter::configureInput($this->getDefinition());
        Table::addFormatOption($this->getDefinition());
        $this->addProjectOption()->addNoWaitOption();
        $this->addExample('Read all project properties')
             ->addExample("Show the project's Git URL", 'git')
             ->addExample("Change the project's title", 'title "My project"');
        $this->setHiddenAliases(['project:metadata']);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $project = $this->getSelectedProject();
        $this->formatter = new PropertyFormatter($input);

        if ($input->getOption('refresh')) {
            $project->refresh();
        }

        $property = $input->getArgument('property');

        if (!$property) {
            return $this->listProperties($project->getProperties(), new Table($input, $output));
        }

        $value = $input->getArgument('value');
        if ($value !== null) {
            return $this->setProperty($property, $value, $project, $input->getOption('no-wait'));
        }

        switch ($property) {
            case 'git':
                $value = $project->getGitUrl(false);
                break;

            default:
                $value = $this->api()->getNestedProperty($project, $property);
        }

        $output->writeln($this->formatter->format($value, $property));

        return 0;
    }

    /**
     * @param array $properties
     * @param Table $table
     *
     * @return int
     */
    protected function listProperties(array $properties, Table $table)
    {
        $headings = [];
        $values = [];
        foreach ($properties as $key => $value) {
            $headings[] = new AdaptiveTableCell($key, ['wrap' => false]);
            $values[] = $this->formatter->format($value, $key);
        }
        $table->renderSimple($values, $headings);

        return 0;
    }

    /**
     * @param string  $property
     * @param string  $value
     * @param Project $project
     * @param bool    $noWait
     *
     * @return int
     */
    protected function setProperty($property, $value, Project $project, $noWait)
    {
        if (!$this->validateValue($property, $value)) {
            return 1;
        }
        $type = $this->getType($property);
        if ($type === 'boolean' && $value === 'false') {
            $value = false;
        }
        settype($value, $type);
        $currentValue = $project->getProperty($property);
        if ($currentValue === $value) {
            $this->stdErr->writeln(
                "Property <info>$property</info> already set as: " . $this->formatter->format($value, $property)
            );

            return 0;
        }

        $project->ensureFull();
        $result = $project->update([$property => $value]);
        $this->stdErr->writeln("Property <info>$property</info> set to: " . $this->formatter->format($value, $property));

        $this->api()->clearProjectsCache();

        $success = true;
        if (!$noWait) {
            $success = ActivityUtil::waitMultiple($result->getActivities(), $this->stdErr, $project);
        }

        return $success ? 0 : 1;
    }

    /**
     * Get the type of a writable property.
     *
     * @param string $property
     *
     * @return string|false
     */
    protected function getType($property)
    {
        $writableProperties = ['title' => 'string'];

        return isset($writableProperties[$property]) ? $writableProperties[$property] : false;
    }

    /**
     * @param string          $property
     * @param string          $value
     *
     * @return bool
     */
    protected function validateValue($property, $value)
    {
        $type = $this->getType($property);
        if (!$type) {
            $this->stdErr->writeln("Property not writable: <error>$property</error>");

            return false;
        }

        return true;
    }

}
