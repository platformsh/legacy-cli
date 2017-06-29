<?php

namespace Platformsh\Cli\Command\Config;

use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;

class PhpCommand extends ConfigGenerateCommandBase
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
     * @return Field[]
     */
    public static function getCommonFields()
    {
        $fields = [];

        $fields['php_version'] = new OptionsField('PHP version', [
            'optionName' => 'php-version',
            'options' => ['5.6', '7.0', '7.1'],
            'default' => '7.1',
        ]);

        $fields['build_flavor'] = new OptionsField('Build flavor', [
            'optionName' => 'flavor',
            'options' => ['composer', 'none'],
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

    /**
     * {@inheritdoc}
     */
    protected function getFields()
    {
        return self::getCommonFields();
    }
}
