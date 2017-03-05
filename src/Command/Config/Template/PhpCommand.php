<?php

namespace Platformsh\Cli\Command\Config\Template;

use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;

class PhpCommand extends ConfigTemplateCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function getKey()
    {
        return 'php';
    }

    /**
     * {@inheritdoc}
     */
    protected function getLabel()
    {
        return 'Generic PHP';
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

        $fields['build_flavor'] = new OptionsField('Build flavor', [
            'optionName' => 'flavor',
            'options' => ['composer', 'drupal', 'none'],
            'default' => 'composer',
        ]);

        $fields['webroot'] = new Field('Web root directory', [
            'optionName' => 'webroot',
            'default' => 'web',
            'validator' => function ($value) {
                if (preg_match('/^\/.*/', $value)) {
                    return 'The web root must not begin with a /. It is a directory relative to the application root.';
                }
                if (preg_match('/\s+/', $value)) {
                    return 'The web root must not contain spaces.';
                }
                return true;
            },
        ]);

        $fields['front_controller'] = new Field('Front controller', [
            'optionName' => 'front-controller',
            'default' => '/index.php',
            'validator' => function ($value) {
                if (!preg_match('/^\/.*/', $value)) {
                    return 'The front controller must be an absolute URL path to a PHP file, starting with /.';
                }
                if (preg_match('/^\w*\.php/', $value)) {
                    return 'The front controller must end in .php and contain no spaces.';
                }
                return true;
            },
        ]);

        return $fields;
    }
}
