<?php

namespace Platformsh\Cli\Command\Config;

class Drupal7Command extends ConfigGenerateCommandBase
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
        $commonFields = PhpCommand::getCommonFields();

        $fields['php_version'] = $commonFields['php_version'];
        $fields['webroot'] = $commonFields['webroot']
            ->set('default', 'public');

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
    }
}
