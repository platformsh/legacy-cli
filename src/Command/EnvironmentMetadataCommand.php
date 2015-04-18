<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Util\PropertyFormatter;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentMetadataCommand extends PlatformCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('environment:metadata')
          ->addArgument('property', InputArgument::OPTIONAL, 'The name of the property')
          ->addArgument('value', InputArgument::OPTIONAL, 'Set a new value for the property')
          ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache')
          ->setDescription('Read or set metadata for an environment')
          ->setHelp(
            <<<EOF
            Use this command to read or write an environment's metadata.

<comment>Examples:</comment>
Read all environment metadata:
  <info>platform %command.name%</info>

Show the environment status:
  <info>platform %command.name% status</info>

Show the date the environment was created:
  <info>platform %command.name% created_at</info>

Enable email sending:
  <info>platform %command.name% enable_smtp true</info>

Change the environment title:
  <info>platform %command.name% title "New feature"</info>

Change the environment's parent branch:
  <info>platform %command.name% parent sprint-2</info>
EOF
          );
        $this->addProjectOption()
             ->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $environment = $this->getSelectedEnvironment();
        if ($input->getOption('refresh')) {
            $this->getEnvironments($this->getSelectedProject(), true);
        }

        $property = $input->getArgument('property');

        if (!$property) {
            return $this->listProperties($environment, $output);
        }

        $value = $input->getArgument('value');
        if ($value !== null) {
            return $this->setProperty($property, $value, $environment, $output);
        }

        $output->writeln($environment->getProperty($property));

        return 0;
    }

    /**
     * @param Environment     $environment
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function listProperties(Environment $environment, OutputInterface $output)
    {
        $output->writeln("Metadata for the environment <info>" . $environment['id'] . "</info>:");
        $formatter = new PropertyFormatter();

        $table = new Table($output);
        $table->setHeaders(array("Property", "Value"));
        foreach ($environment->getProperties() as $key => $value) {
            $table->addRow(array($key, $formatter->format($value, $key)));
        }
        $table->render();

        return 0;
    }

    /**
     * @param string          $property
     * @param string          $value
     * @param Environment     $environment
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function setProperty($property, $value, Environment $environment, OutputInterface $output)
    {
        if (!$this->validateValue($property, $value, $output)) {
            return 1;
        }
        $type = $this->getType($property);
        if ($type === 'boolean' && $value === 'false') {
            $value = false;
        }
        settype($value, $type);
        $currentValue = $environment->getProperty($property, false);
        if ($currentValue === $value) {
            $output->writeln(
              "Property <info>$property</info> already set as: " . $environment->getProperty($property, false)
            );

            return 0;
        }
        $environment->update(array($property => $value));
        $output->writeln("Property <info>$property</info> set to: " . $environment[$property]);
        if ($property === 'enable_smtp' && !$environment->getLastActivity()) {
            $this->rebuildWarning($output);
        }

        return 0;
    }

    /**
     * Get the type of a writable environment property.
     *
     * @param string $property
     *
     * @return string|false
     */
    protected function getType($property)
    {
        $writableProperties = array(
          'enable_smtp' => 'boolean',
          'parent' => 'string',
          'title' => 'string',
        );

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
        $valid = true;
        $message = '';
        // @todo find out exactly how these should best be validated
        $selectedEnvironment = $this->getSelectedEnvironment();
        switch ($property) {
            case 'parent':
                if ($selectedEnvironment['id'] === 'master') {
                    $message = "The master environment cannot have a parent";
                    $valid = false;
                } elseif ($value === $selectedEnvironment['id']) {
                    $message = "An environment cannot be the parent of itself";
                    $valid = false;
                } elseif (!$parentEnvironment = $this->getEnvironment($value)) {
                    $message = "Environment not found: <error>$value</error>";
                    $valid = false;
                } elseif ($parentEnvironment['parent'] === $selectedEnvironment['id']) {
                    $valid = false;
                }
                break;

        }
        switch ($type) {
            case 'boolean':
                $valid = in_array($value, array('1', '0', 'false', 'true'));
                break;

        }
        if (!$valid) {
            if ($message) {
                $output->writeln($message);
            } else {
                $output->writeln("Invalid value for <error>$property</error>: $value");
            }

            return false;
        }

        return true;
    }

}
