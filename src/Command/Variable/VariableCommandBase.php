<?php

namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Client\Model\ProjectLevelVariable;
use Platformsh\Client\Model\Resource as ApiResource;
use Platformsh\Client\Model\Variable as EnvironmentLevelVariable;
use Platformsh\ConsoleForm\Field\BooleanField;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;

abstract class VariableCommandBase extends CommandBase
{
    /**
     * Get an existing variable by name.
     *
     * @param string $name
     *
     * @return \Platformsh\Client\Model\ProjectLevelVariable|\Platformsh\Client\Model\Variable|false
     */
    protected function getExistingVariable($name)
    {
        // @todo allow specifying the level
        if ($this->hasSelectedEnvironment()) {
            $variable = $this->getSelectedEnvironment()->getVariable($name);
            if ($variable !== false) {
                return $variable;
            }
        }

        return $this->getSelectedProject()->getVariable($name);
    }

    protected function displayVariable(ApiResource $variable)
    {
        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $properties = $variable->getProperties();
        $properties['level'] = $this->getVariableLevel($variable);

        $headings = [];
        $values = [];
        foreach ($properties as $key => $value) {
            $headings[] = new AdaptiveTableCell($key, ['wrap' => false]);
            if ($key === 'value') {
                $value = wordwrap($value, 80, "\n", true);
            }
            $values[] = $formatter->format($value, $key);
        }
        $table->renderSimple($values, $headings);
    }

    /**
     * @param ApiResource $variable
     *
     * @return string
     */
    protected function getVariableLevel(ApiResource $variable)
    {
        if ($variable instanceof EnvironmentLevelVariable) {
            return 'environment';
        } elseif ($variable instanceof ProjectLevelVariable) {
            return 'project';
        }
        throw new \RuntimeException('Variable level not found');
    }

    /**
     * @return Field[]
     */
    protected function getFields()
    {
        return [
            'level' => new OptionsField('Level', [
                'description' => 'The level at which to set the variable',
                'options' => [
                    'project' => 'Project-wide',
                    'environment' => 'Environment-specific',
                ],
            ]),
            'environment' => new OptionsField('Environment', [
                'conditions' => [
                    'level' => 'environment',
                ],
                'optionName' => false,
                'questionLine' => 'On what environment should the variable be set?',
                'optionsCallback' => function () {
                    return array_keys($this->api()->getEnvironments($this->getSelectedProject()));
                },
                'asChoice' => false,
                'includeAsOption' => false,
                'default' => $this->hasSelectedEnvironment() ? $this->getSelectedEnvironment()->id : null,
            ]),
            'name' => new Field('Name', [
                'description' => 'The variable name',
            ]),
            'value' => new Field('Value', [
                'description' => "The variable's value (a string, or JSON)",
            ]),
            'is_json' => new BooleanField('JSON', [
                'description' => 'Whether the variable is JSON-formatted',
                'questionLine' => 'Is the value JSON-formatted',
                'default' => false,
            ]),
            'is_sensitive' => new BooleanField('Sensitive', [
                'conditions' => [
                    'level' => 'environment',
                ],
                'description' => 'Whether the variable is sensitive',
                'questionLine' => 'Is the value sensitive?',
                'default' => false,
            ]),
            'prefix' => new OptionsField('Prefix', [
                'description' => "The variable name's prefix",
                'conditions' => [
                    'name' => function ($name) {
                        return strpos($name, ':') === false;
                    }
                ],
                'options' => [
                    'none' => 'No prefix (wrapped in ' . $this->config()->get('service.env_prefix') . 'VARIABLES)',
                    'env' => 'env: Exposed directly in the environment',
                ],
                'allowOther' => true,
                'default' => 'none',
            ]),
            'is_enabled' => new BooleanField('Enabled', [
                'optionName' => 'enabled',
                'conditions' => [
                    'level' => 'environment',
                ],
                'description' => 'Whether the variable should be enabled',
                'questionLine' => 'Should the variable be enabled?',
            ]),
            'is_inheritable' => new BooleanField('Inheritable', [
                'conditions' => [
                    'level' => 'environment',
                ],
                'description' => 'Whether the variable is inheritable by child environments',
                'questionLine' => 'Is the variable inheritable (by child environments)?',
            ]),
            'visible_build' => new BooleanField('Visible at build time', [
                'optionName' => 'visible-build',
                'conditions' => [
                    'level' => 'project',
                ],
                'description' => 'Whether the variable should be visible at build time',
                'questionLine' => 'Should the variable be available at build time?',
            ]),
            'visible_runtime' => new BooleanField('Visible at runtime', [
                'optionName' => 'visible-runtime',
                'conditions' => [
                    'level' => 'project',
                ],
                'description' => 'Whether the variable should be visible at runtime',
                'questionLine' => 'Should the variable be available at runtime?',
            ]),
        ];
    }
}
