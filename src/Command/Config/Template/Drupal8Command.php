<?php

namespace Platformsh\Cli\Command\Config\Template;

use Platformsh\ConsoleForm\Field\BooleanField;

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
        $fields['php_version'] = PhpCommand::getCommonFields()['php_version'];

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
            'disk' => 2048,
        ];
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
            unset($parameters['with_redis_cache']);
        }
    }
}
