<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\PlatformCommand;
use Platformsh\Cli\Util\ActivityUtil;
use Platformsh\Cli\Util\PropertyFormatter;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentInfoCommand extends PlatformCommand
{
    /** @var PropertyFormatter */
    protected $formatter;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('environment:info')
          ->addArgument('property', InputArgument::OPTIONAL, 'The name of the property')
          ->addArgument('value', InputArgument::OPTIONAL, 'Set a new value for the property')
          ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache')
          ->setDescription('Read or set properties for an environment');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addNoWaitOption();
        $this->addExample('Read all environment properties')
          ->addExample("Show the environment's status", 'status')
          ->addExample('Show the date the environment was created', 'created_at')
          ->addExample('Enable email sending', 'enable_smtp true')
          ->addExample('Change the environment title', 'title "New feature"')
          ->addExample("Change the environment's parent branch", 'parent sprint-2');
        $this->setHiddenAliases(array('environment:metadata'));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();
        if ($input->getOption('refresh')) {
            $project = $this->getSelectedProject();
            $environment = $this->getEnvironment($environment->id, $project, true);
        }

        $property = $input->getArgument('property');

        $this->formatter = new PropertyFormatter();

        if (!$property) {
            return $this->listProperties($environment, $output);
        }

        $value = $input->getArgument('value');
        if ($value !== null) {
            return $this->setProperty($property, $value, $environment, $input->getOption('no-wait'));
        }

        $output->writeln($this->formatter->format($environment->getProperty($property), $property));

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
        $table = new Table($output);
        $table->setHeaders(array("Property", "Value"));
        foreach ($environment->getProperties() as $key => $value) {
            $table->addRow(array($key, $this->formatter->format($value, $key)));
        }
        $table->render();

        return 0;
    }

    /**
     * @param string      $property
     * @param string      $value
     * @param Environment $environment
     * @param bool        $noWait
     *
     * @return int
     */
    protected function setProperty($property, $value, Environment $environment, $noWait)
    {
        if (!$this->validateValue($property, $value)) {
            return 1;
        }
        $type = $this->getType($property);
        if ($type === 'boolean' && $value === 'false') {
            $value = false;
        }
        settype($value, $type);
        $currentValue = $environment->getProperty($property, false);
        if ($currentValue === $value) {
            $this->stdErr->writeln(
              "Property <info>$property</info> already set as: " . $this->formatter->format($environment->getProperty($property, false), $property)
            );

            return 0;
        }
        $result = $environment->update(array($property => $value));
        $this->stdErr->writeln("Property <info>$property</info> set to: " . $this->formatter->format($environment[$property], $property));

        $this->clearEnvironmentsCache();

        $rebuildProperties = array('enable_smtp', 'restrict_robots');
        $success = true;
        if ($result->countActivities() && !$noWait) {
            $success = ActivityUtil::waitOnResult($result, $this->stdErr);
        }
        elseif (!$result->countActivities() && in_array($property, $rebuildProperties)) {
            $this->rebuildWarning();
        }

        return $success ? 0 : 1;
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
          'restrict_robots' => 'boolean',
        );

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
                $this->stdErr->writeln($message);
            } else {
                $this->stdErr->writeln("Invalid value for <error>$property</error>: $value");
            }

            return false;
        }

        return true;
    }

}
