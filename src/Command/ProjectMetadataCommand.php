<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Util\PropertyFormatter;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectMetadataCommand extends PlatformCommand
{
    /** @var PropertyFormatter */
    protected $formatter;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('project:metadata')
          ->addArgument('property', InputArgument::OPTIONAL, 'The name of the property')
          ->addArgument('value', InputArgument::OPTIONAL, 'Set a new value for the property')
          ->setDescription('Read or set metadata for a project');
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $project = $this->getSelectedProject();
        $this->formatter = new PropertyFormatter($project);

        $property = $input->getArgument('property');

        if (!$property) {
            return $this->listProperties($project, $output);
        }

        $value = $input->getArgument('value');
        if ($value !== null) {
            return $this->setProperty($property, $value, $project, $output);
        }

        $output->writeln($project->getProperty($property));

        return 0;
    }

    /**
     * @param Project         $project
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function listProperties(Project $project, OutputInterface $output)
    {
        $output->writeln("Metadata for the project <info>" . $project['id'] . "</info>:");

        $table = new Table($output);
        $table->setHeaders(array("Property", "Value"));
        foreach ($project->getProperties() as $key => $value) {
            $table->addRow(array($key, $this->formatter->format($value, $key)));
        }
        $table->render();

        return 0;
    }

    /**
     * @param string          $property
     * @param string          $value
     * @param Project         $project
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function setProperty($property, $value, Project $project, OutputInterface $output)
    {
        if (!$this->validateValue($property, $value, $output)) {
            return 1;
        }
        $type = $this->getType($property);
        if ($type === 'boolean' && $value === 'false') {
            $value = false;
        }
        settype($value, $type);
        $currentValue = $project->getProperty($property);
        if ($currentValue === $value) {
            $output->writeln(
              "Property <info>$property</info> already set as: " . $this->formatter->format($value, $property)
            );

            return 0;
        }
        $project->update(array($property => $value));
        $output->writeln("Property <info>$property</info> set to: " . $this->formatter->format($value, $property));

        return 0;
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
        $writableProperties = array();

        return isset($writableProperties[$property]) ? $writableProperties[$property] : false;
    }

    /**
     * @param string          $property
     * @param string          $value
     * @param OutputInterface $output
     *
     * @return bool
     */
    protected function validateValue($property, $value, OutputInterface $output)
    {
        $type = $this->getType($property);
        if (!$type) {
            $output->writeln("Property not writable: <error>$property</error>");

            return false;
        }

        return true;
    }

}
