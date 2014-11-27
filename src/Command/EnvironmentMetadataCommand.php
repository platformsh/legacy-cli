<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Environment;
use CommerceGuys\Platform\Cli\Model\HalResource;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class EnvironmentMetadataCommand extends EnvironmentCommand
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
            ->setDescription('Read or set metadata for an environment');
        $this->addProjectOption()->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $environment = new Environment($this->environment, $client);

        $property = $input->getArgument('property');

        if (!$property) {
            $client->setBaseUrl($this->project['endpoint']);
            $environment = Environment::get($this->environment['id'], 'environments', $client);
            return $this->listProperties($environment, $output);
        }

        $value = $input->getArgument('value');
        if ($value !== null) {
            return $this->setProperty($property, $value, $environment, $output);
        }

        $output->writeln($environment->getPropertyFormatted($property));
        return 0;
    }

    /**
     * @param HalResource     $environment
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function listProperties(HalResource $environment, OutputInterface $output)
    {
        $output->writeln("Metadata for the environment <info>" . $environment->id() . "</info>:");

        $table = new Table($output);
        $table->setHeaders(array("Property", "Value"));
        foreach ($environment->getPropertiesFormatted() as $key => $value) {
            $table->addRow(array($key, $value));
        }
        $table->render();
        return 0;
    }

    /**
     * @param string          $property
     * @param string          $value
     * @param HalResource     $environment
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function setProperty($property, $value, HalResource $environment, OutputInterface $output)
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
            $output->writeln("Property <info>$property</info> already set as: " . $environment->getPropertyFormatted($property, false));
            return 0;
        }
        $environment->update(array($property => $value));
        $this->getEnvironment($this->environment['id'], $this->project, true);
        $output->writeln("Property <info>$property</info> set to: " . $environment->getPropertyFormatted($property));
        if ($property === 'enable_smtp' && !$environment->hasActivity()) {
            $this->rebuildWarning($output);
        }
        return 0;
    }

    /**
     * Get the type of a writable environment property.
     *
     * @param string $property
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
     * @param string $property
     * @param string $value
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
        switch ($property) {
            case 'parent':
                if ($this->environment['id'] === 'master') {
                    $message = "The master environment cannot have a parent";
                    $valid = false;
                }
                elseif ($value === $this->environment['id']) {
                    $message = "An environment cannot be the parent of itself";
                    $valid = false;
                }
                elseif (!$parentEnvironment = $this->getEnvironment($value)) {
                    $message = "Environment not found: <error>$value</error>";
                    $valid = false;
                }
                elseif ($parentEnvironment['parent'] === $this->environment['id']) {
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
            }
            else {
                $output->writeln("Invalid value for <error>$property</error>: $value");
            }
            return false;
        }
        return true;
    }

}
