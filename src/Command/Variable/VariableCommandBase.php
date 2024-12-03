<?php

namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Selector\Selection;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Api;
use Platformsh\Client\Model\Environment;
use Symfony\Contracts\Service\Attribute\Required;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Client\Model\ApiResourceBase;
use Platformsh\Client\Model\ProjectLevelVariable;
use Platformsh\Client\Model\Variable as EnvironmentLevelVariable;
use Platformsh\ConsoleForm\Field\BooleanField;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;

abstract class VariableCommandBase extends CommandBase
{
    private readonly Selector $selector;
    private readonly Table $table;
    private readonly PropertyFormatter $propertyFormatter;
    private readonly Config $config;
    private readonly Api $api;

    const LEVEL_PROJECT = 'project';
    const LEVEL_ENVIRONMENT = 'environment';

    protected ?Selection $selection = null;

    #[Required]
    public function autowire(Api $api, Config $config, PropertyFormatter $propertyFormatter, Selector $selector, Table $table) : void
    {
        $this->api = $api;
        $this->config = $config;
        $this->propertyFormatter = $propertyFormatter;
        $this->table = $table;
        $this->selector = $selector;
    }

    /**
     * @param string $str
     *
     * @return string
     */
    protected function escapeShellArg(string $str)
    {
        return (new ArgvInput(['example']))->escapeToken($str);
    }

    /**
     * Add the --level option.
     */
    protected function addLevelOption()
    {
        $this->addOption('level', 'l', InputOption::VALUE_REQUIRED, "The variable level ('project', 'environment', 'p' or 'e')");
    }

    /**
     * Get the requested variable level.
     *
     * @param InputInterface $input
     *
     * @return string|null
     */
    protected function getRequestedLevel(InputInterface $input): ?string
    {
        $str = $input->getOption('level');
        if (empty($str)) {
            return null;
        }
        foreach ([self::LEVEL_PROJECT, self::LEVEL_ENVIRONMENT] as $validLevel) {
            if (stripos($validLevel, (string) $str) === 0) {
                return $validLevel;
            }
        }
        throw new InvalidArgumentException('Invalid level: ' . $str);
    }

    /**
     * Finds an existing variable by name.
     *
     * @param string $name
     * @param Selection $selection
     * @param string|null $level
     * @param bool $messages Whether to print error messages to
     *                              $this->stdErr if the variable is not found.
     *
     * @return ProjectLevelVariable|EnvironmentLevelVariable|false
     */
    protected function getExistingVariable(string $name, Selection $selection, ?string $level, bool $messages = true): EnvironmentLevelVariable|false|ProjectLevelVariable
    {
        $output = $messages ? $this->stdErr : new NullOutput();

        if ($level === self::LEVEL_ENVIRONMENT || ($selection->hasEnvironment() && $level === null)) {
            $variable = $selection->getEnvironment()->getVariable($name);
            if ($variable !== false) {
                if ($level === null && $selection->getProject()->getVariable($name)) {
                    $output->writeln('Variable found at both project and environment levels: <error>' . $name . '</error>');
                    $output->writeln("To select a variable, use the --level option ('" . self::LEVEL_PROJECT . "' or '" . self::LEVEL_ENVIRONMENT . "').");

                    return false;
                }

                return $variable;
            }
        }
        if ($level !== self::LEVEL_ENVIRONMENT) {
            $variable = $selection->getProject()->getVariable($name);
            if ($variable !== false) {
                return $variable;
            }
        }
        $output->writeln('Variable not found: <error>' . $name . '</error>');

        return false;
    }

    /**
     * Display a variable to stdout.
     */
    protected function displayVariable(ApiResourceBase $variable)
    {
        $table = $this->table;
        $formatter = $this->propertyFormatter;

        $properties = $variable->getProperties();
        $properties['level'] = $this->getVariableLevel($variable);

        $headings = [];
        $values = [];
        foreach ($properties as $key => $value) {
            $headings[] = new AdaptiveTableCell($key, ['wrap' => false]);
            if ($key === 'value') {
                $value = wordwrap((string) $value, 80, "\n", true);
            }
            $values[] = $formatter->format($value, $key);
        }
        $table->renderSimple($values, $headings);
    }

    /**
     * @param ApiResourceBase $variable
     *
     * @return string
     */
    protected function getVariableLevel(ApiResourceBase $variable)
    {
        if ($variable instanceof EnvironmentLevelVariable) {
            return self::LEVEL_ENVIRONMENT;
        } elseif ($variable instanceof ProjectLevelVariable) {
            return self::LEVEL_PROJECT;
        }
        throw new \RuntimeException('Variable level not found');
    }

    /**
     * @return Field[]
     */
    protected function getFields(): array
    {
        $fields = [];

        $fields['level'] = new OptionsField('Level', [
            'description' => 'The level at which to set the variable',
            'shortcut' => 'l',
            'options' => [
                self::LEVEL_PROJECT => 'Project-wide',
                self::LEVEL_ENVIRONMENT => 'Environment-specific',
            ],
            'normalizer' => function ($value) {
                foreach ([self::LEVEL_PROJECT, self::LEVEL_ENVIRONMENT] as $validLevel) {
                    if (stripos($validLevel, $value) === 0) {
                        return $validLevel;
                    }
                }

                return $value;
            },
        ]);
        $fields['environment'] = new OptionsField('Environment', [
            'conditions' => [
                'level' => self::LEVEL_ENVIRONMENT,
            ],
            'questionLine' => 'On what environment should the variable be set?',
            'optionsCallback' => fn(): array => array_keys($this->api->getEnvironments($this->selection->getProject())),
            'asChoice' => false,
            'includeAsOption' => false,
            'defaultCallback' => function () {
                if ($this->selection->hasEnvironment()) {
                    return $this->selection->getEnvironment()->id;
                }
                return null;
            },
        ]);
        $fields['name'] = new Field('Name', [
            'description' => 'The variable name',
            'validators' => [
                fn($value): string|true => strlen((string) $value) > 256
                    ? 'The variable name exceeds the maximum length, 256 characters.'
                    : true,
                fn($value): string|true => str_contains((string) $value, ' ')
                    ? 'The variable name must not contain a space.'
                    : true,
            ],
        ]);
        $fields['value'] = new Field('Value', [
            'description' => "The variable's value",
        ]);
        $fields['is_json'] = new BooleanField('JSON', [
            'description' => 'Whether the variable value is JSON-formatted',
            'questionLine' => 'Is the value JSON-formatted?',
            'default' => false,
            'avoidQuestion' => true,
        ]);
        $fields['is_sensitive'] = new BooleanField('Sensitive', [
            'description' => 'Whether the variable value is sensitive',
            'questionLine' => 'Is the value sensitive?',
            'default' => false,
            'avoidQuestion' => true,
        ]);
        $fields['prefix'] = new OptionsField('Prefix', [
            'description' => "The variable name's prefix which can determine its type, e.g. 'env'. Only applicable if the name does not already contain a prefix.",
            'conditions' => [
                'name' => fn($name): bool => !str_contains((string) $name, ':')
            ],
            'options' => $this->getPrefixOptions('NAME'),
            'optionsCallback' => fn(array $previousValues) => $this->getPrefixOptions($previousValues['name'] ?? 'NAME'),
            'allowOther' => true,
            'default' => 'none',
        ]);
        $fields['is_enabled'] = new BooleanField('Enabled', [
            'optionName' => 'enabled',
            'conditions' => [
                'level' => self::LEVEL_ENVIRONMENT,
            ],
            'description' => 'Whether the variable should be enabled on the environment',
            'questionLine' => 'Should the variable be enabled?',
            'avoidQuestion' => true,
        ]);
        $fields['is_inheritable'] = new BooleanField('Inheritable', [
            'conditions' => [
                'level' => self::LEVEL_ENVIRONMENT,
            ],
            'description' => 'Whether the variable is inheritable by child environments',
            'questionLine' => 'Is the variable inheritable (by child environments)?',
            'avoidQuestion' => true,
        ]);
        $fields['visible_build'] = new BooleanField('Visible at build time', [
            'optionName' => 'visible-build',
            'description' => 'Whether the variable should be visible at build time',
            'questionLine' => 'Should the variable be available at build time?',
            'defaultCallback' => fn(array $values): bool =>
                // Variables that are visible at build-time will affect the
                // build cache, so it is good to minimise the number of them.
                // This defaults to true for project-level variables, false otherwise.
                isset($values['level']) && $values['level'] === self::LEVEL_PROJECT,
            'avoidQuestion' => true,
        ]);
        $fields['visible_runtime'] = new BooleanField('Visible at runtime', [
            'optionName' => 'visible-runtime',
            'description' => 'Whether the variable should be visible at runtime',
            'questionLine' => 'Should the variable be available at runtime?',
            'avoidQuestion' => true,
        ]);

        return $fields;
    }

    /**
     * @param string $name
     *
     * @return array
     */
    private function getPrefixOptions(string $name): array
    {
        return [
            'none' => 'No prefix: The variable will be part of <comment>$' . $this->config->get('service.env_prefix') . 'VARIABLES</comment>.',
            'env:' => 'env: The variable will be exposed directly, e.g. as <comment>$' . strtoupper($name) . '</comment>.',
        ];
    }
}
