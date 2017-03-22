<?php

namespace Platformsh\Cli\Command\Config\Template;

use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;

class Drupal7Command extends ConfigTemplateCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function getKey()
    {
        return 'drupal7';
    }

    /**
     * {@inheritdoc}
     */
    protected function getLabel()
    {
        return 'Drupal 7';
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

        $fields['webroot'] = new Field('Web root', [
            'optionName' => 'webroot',
            'default' => 'public',
        ]);

        return $fields;
    }

    /**
     * {@inheritdoc}
     */
    protected function getTemplate($type)
    {
        $templates = [
            'app' => 'https://github.com/pjcdawkins/config-templates/raw/drupal7/.platform.app.yaml',
            'routes' => 'https://github.com/pjcdawkins/config-templates/raw/drupal7/.platform/routes.yaml',
            'services' => 'https://github.com/pjcdawkins/config-templates/raw/drupal7/.platform/services.yaml',
        ];

        return $this->download($templates[$type]);
    }
}
