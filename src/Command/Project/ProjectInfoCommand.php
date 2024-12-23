<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'project:info', description: 'Read or set properties for a project')]
class ProjectInfoCommand extends CommandBase
{
    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('property', InputArgument::OPTIONAL, 'The name of the property')
            ->addArgument('value', InputArgument::OPTIONAL, 'Set a new value for the property')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());
        $this->addExample('Read all project properties')
             ->addExample("Show the project's Git URL", 'git')
             ->addExample("Change the project's title", 'title "My project"');
        $this->setHiddenAliases(['project:metadata']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);

        $project = $selection->getProject();

        if ($input->getOption('refresh')) {
            $project->refresh();
        }

        $property = $input->getArgument('property');

        // Setting the pseudo-properties 'git' and 'url', and un-setting the
        // property 'entropy', are done twice in this command so that
        // lazy-loading still works.
        if (!$property) {
            $properties = $project->getProperties();
            $properties['git'] = $project->getGitUrl();
            $properties['url'] = $project->getUri();
            unset($properties['entropy']);

            return $this->listProperties($properties);
        }

        $value = $input->getArgument('value');
        if ($value !== null) {
            return $this->setProperty($property, $value, $project, !$this->activityMonitor->shouldWait($input));
        }

        switch ($property) {
            case 'git':
                $value = $project->getGitUrl();
                break;

            case 'url':
                $value = $project->getUri();
                break;

            case 'entropy':
                throw new \InvalidArgumentException('Property not found: ' . $property);

            default:
                $value = $this->api->getNestedProperty($project, $property);
        }

        $output->writeln($this->propertyFormatter->format($value, $property));

        return 0;
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return int
     */
    protected function listProperties(array $properties): int
    {
        $headings = [];
        $values = [];
        foreach ($properties as $key => $value) {
            $headings[] = new AdaptiveTableCell($key, ['wrap' => false]);
            $values[] = $this->propertyFormatter->format($value, $key);
        }
        $this->table->renderSimple($values, $headings);

        return 0;
    }

    protected function setProperty(string $property, string $value, Project $project, bool $noWait): int
    {
        $type = $this->getType($property);
        if (!$type) {
            $this->stdErr->writeln("Property not writable: <error>$property</error>");
            return 1;
        }
        if ($type === 'boolean' && $value === 'false') {
            $value = false;
        }
        settype($value, $type);
        $currentValue = $project->getProperty($property);
        if ($currentValue === $value) {
            $this->stdErr->writeln(
                "Property <info>$property</info> already set as: " . $this->propertyFormatter->format($value, $property),
            );

            return 0;
        }

        $project->ensureFull();
        $result = $project->update([$property => $value]);
        $this->stdErr->writeln(sprintf(
            'Property <info>%s</info> set to: %s',
            $property,
            $this->propertyFormatter->format($value, $property),
        ));

        $this->api->clearProjectsCache();

        $success = true;
        if (!$noWait) {
            $activityMonitor = $this->activityMonitor;
            $success = $activityMonitor->waitMultiple($result->getActivities(), $project);
        }

        return $success ? 0 : 1;
    }

    /**
     * Gets the type of a writable property.
     */
    protected function getType(string $property): string|false
    {
        $writableProperties = [
            'title' => 'string',
            'description' => 'string',
            'default_domain' => 'string',
            'default_branch' => 'string',
        ];

        return $writableProperties[$property] ?? false;
    }
}
