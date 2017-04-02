<?php

namespace Platformsh\Cli\Command\Config\Template;

use Platformsh\ConsoleForm\Field\BooleanField;
use Platformsh\ConsoleForm\Field\Field;

class Drupal8Command extends ConfigTemplateCommandBase
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

        $fields['db_disk'] = new Field('Database disk size (MB)', [
            'optionName' => 'db-disk',
            'default' => 2048,
            'validator' => function ($value) {
                return is_numeric($value) && $value > 1024;
            },
        ]);

        $fields['with_redis_cache'] = new BooleanField('Add a Redis cache service', [
            'optionName' => 'redis-cache',
            'default' => false,
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
        unset($parameters['db_disk']);
        $parameters['relationships']['database'] = [
            'service' => 'mysqldb',
            'endpoint' => 'mysql',
        ];
        if ($parameters['with_redis_cache']) {
            $parameters['services']['rediscache'] = [
                'type' => 'redis:3.0',
            ];
            $parameters['relationships']['redis'] = [
                'service' => 'rediscache',
                'endpoint' => 'redis',
            ];
        }
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
