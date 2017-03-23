<?php

namespace Platformsh\Cli\Command\Config\Template;

use Platformsh\ConsoleForm\Field\OptionsField;

class DrupalCommand extends ConfigTemplateCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function getKey()
    {
        return 'drupal';
    }

    /**
     * {@inheritdoc}
     */
    protected function getLabel()
    {
        return 'Drupal';
    }

    /**
     * {@inheritdoc}
     */
    protected function getFields()
    {
        $fields['php_version'] = new OptionsField('PHP version', [
            'optionName' => 'php-version',
            'options' => ['7.1', '7.0', '5.6'],
            'default' => '7.1',
        ]);

        return $fields;
    }

    /**
     * {@inheritdoc}
     */
    protected function getTemplate($type)
    {
        $templates = [
            'app' => 'https://github.com/pjcdawkins/config-templates/raw/drupal/.platform.app.yaml',
            'routes' => 'https://github.com/pjcdawkins/config-templates/raw/drupal/.platform/routes.yaml',
            'services' => 'https://github.com/pjcdawkins/config-templates/raw/drupal/.platform/services.yaml',
        ];

        return $templates[$type];
    }
}
