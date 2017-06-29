<?php

namespace Platformsh\Cli\Command\Config;

use Platformsh\ConsoleForm\Field\BooleanField;
use Platformsh\ConsoleForm\Field\Field;

class Drupal8Command extends ConfigGenerateCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function getKey()
    {
        return 'drupal8';
    }

    /**
     * {@inheritdoc}
     */
    protected function getLabel()
    {
        return 'Drupal 8';
    }

    /**
     * {@inheritdoc}
     */
    protected function getFields()
    {
        $commonFields = PhpCommand::getCommonFields();

        $fields['php_version'] = $commonFields['php_version'];
        $fields['webroot'] = $commonFields['webroot'];

        $fields['install_profile'] = new Field('Install profile', [
            'optionName' => 'install-profile',
            'default' => 'standard',
        ]);

        $fields['db_disk'] = new Field('Database disk size (MB)', [
            'optionName' => 'db-disk',
            'default' => 2048,
            'validator' => function ($value) {
                return is_numeric($value) && $value >= 512 && $value < 512000;
            },
            'normalizer' => 'intval',
        ]);

        return $fields;
    }

    /**
     * {@inheritdoc}
     */
    protected function alterParameters(array &$parameters)
    {
        $parameters['services']['mysqldb'] = [
            'type' => 'mysql:10.0',
            'disk' => $parameters['db_disk'],
        ];
        $parameters['relationships']['database'] = 'mysqldb:mysql';
    }

    /**
     * {@inheritdoc}
     */
    protected function getTemplateTypes()
    {
        $types = parent::getTemplateTypes();
        $webRoot = isset($this->parameters['webroot']) ? $this->parameters['webroot'] : 'web';
        $types['settings.php'] = $webRoot . '/sites/default/settings.php';
        $types['settings.platformsh.php'] = $webRoot . '/sites/default/settings.platformsh.php';

        return $types;
    }
}
