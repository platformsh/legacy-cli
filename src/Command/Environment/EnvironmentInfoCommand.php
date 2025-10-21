<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'environment:info', description: 'Read or set properties for an environment')]
class EnvironmentInfoCommand extends CommandBase
{
    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly Config $config, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
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
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());
        $this->addExample('Read all environment properties')
             ->addExample("Show the environment's status", 'status')
             ->addExample('Show the date the environment was created', 'created_at')
             ->addExample('Enable email sending', 'enable_smtp true')
             ->addExample('Change the environment title', 'title "New feature"')
             ->addExample("Change the environment's parent branch", 'parent sprint-2')
             ->addExample("Unset the environment's parent branch", 'parent -');
        $this->setHiddenAliases(['environment:metadata']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);

        $environment = $selection->getEnvironment();
        if ($input->getOption('refresh')) {
            $environment->refresh();
        }

        $property = $input->getArgument('property');

        if (!$property) {
            return $this->listProperties($environment);
        }

        $value = $input->getArgument('value');
        if ($value !== null) {
            return $this->setProperty($property, $value, $environment, $selection->getProject(), !$this->activityMonitor->shouldWait($input));
        }

        $value = match ($property) {
            'url' => $environment->getUri(true),
            default => $this->api->getNestedProperty($environment, $property),
        };

        $output->writeln($this->propertyFormatter->format($value, $property));

        return 0;
    }

    /**
     * @param Environment $environment
     *
     * @return int
     */
    protected function listProperties(Environment $environment): int
    {
        $headings = [];
        $values = [];
        foreach ($environment->getProperties() as $key => $value) {
            $headings[] = new AdaptiveTableCell($key, ['wrap' => false]);
            $values[] = $this->propertyFormatter->format($value, $key);
        }

        $headings[] = 'deployment_type';
        $values[] = $environment->getSettings()->enable_manual_deployments ? 'manual' : 'automatic';

        $this->table->renderSimple($values, $headings);

        return 0;
    }

    protected function setProperty(string $property, string $value, Environment $environment, Project $project, bool $noWait): int
    {
        if (!$this->validateValue($property, $value, $environment, $project)) {
            return 1;
        }

        // @todo refactor normalizing the value according to the property (this is a mess)
        $type = $this->getType($property);
        if (!$type) {
            return 1;
        }
        if ($type === 'boolean' && $value === 'false') {
            $value = false;
        }
        if ($property === 'parent' && $value === '-') {
            $value = null;
        } else {
            settype($value, $type);
        }

        $currentValue = $environment->getProperty($property, false);
        if ($currentValue === $value) {
            $this->stdErr->writeln(sprintf(
                'Property <info>%s</info> already set as: %s',
                $property,
                $this->propertyFormatter->format($environment->getProperty($property, false), $property),
            ));

            return 0;
        }
        try {
            $result = $environment->update([$property => $value]);
        } catch (BadResponseException $e) {
            // Translate validation error messages.
            if ($e->getResponse()->getStatusCode() === 400 && ($body = $e->getResponse()->getBody())) {
                $detail = \json_decode((string) $body, true);
                if (\is_array($detail) && !empty($detail['detail'][$property])) {
                    $this->stdErr->writeln("Invalid value for <error>$property</error>: " . $detail['detail'][$property]);
                    return 1;
                }
            }
            throw $e;
        }
        $this->stdErr->writeln(sprintf(
            'Property <info>%s</info> set to: %s',
            $property,
            $this->propertyFormatter->format($environment->$property, $property),
        ));

        $this->api->clearEnvironmentsCache($environment->project);

        $rebuildProperties = ['enable_smtp', 'restrict_robots'];
        $success = true;
        if ($result->countActivities() && !$noWait) {
            $activityMonitor = $this->activityMonitor;
            $success = $activityMonitor->waitMultiple($result->getActivities(), $project);
        } elseif (!$result->countActivities() && in_array($property, $rebuildProperties)) {
            $this->api->redeployWarning();
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
    protected function getType(string $property): string|false
    {
        $writableProperties = [
            'enable_smtp' => 'boolean',
            'parent' => 'string',
            'title' => 'string',
            'restrict_robots' => 'boolean',
            'type' => 'string',
        ];

        return $writableProperties[$property] ?? false;
    }

    protected function validateValue(string $property, string $value, Environment $environment, Project $project): bool
    {
        if ($property == 'deployment_type') {
            $this->stdErr->writeln(
                'Set the deployment type with: <comment>' . $this->config->getStr('application.executable')
                . ' environment:deploy:type</comment>',
            );
            return false;
        }

        $type = $this->getType($property);
        if (!$type) {
            $this->stdErr->writeln("Property not writable: <error>$property</error>");

            return false;
        }
        $valid = true;
        $message = '';
        // @todo find out exactly how these should best be validated
        switch ($property) {
            case 'parent':
                if ($value === '-') {
                    break;
                }
                if ($value === $environment->id) {
                    $message = "An environment cannot be the parent of itself";
                    $valid = false;
                } elseif (!$parentEnvironment = $this->api->getEnvironment($value, $project)) {
                    $message = "Environment not found: <error>$value</error>";
                    $valid = false;
                } elseif ($parentEnvironment->parent === $environment->id) {
                    $valid = false;
                }
                break;
        }
        switch ($type) {
            case 'boolean':
                $valid = in_array($value, ['1', '0', 'false', 'true']);
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
