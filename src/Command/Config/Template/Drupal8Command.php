<?php

namespace Platformsh\Cli\Command\Config\Template;

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

        return $fields;
    }

    /**
     * {@inheritdoc}
     */
    protected function alterParameters(array &$parameters)
    {
        $parameters['services']['mysqldb'] = [
            'type' => 'mysql:10.10',
            'disk' => 2048,
        ];
        $parameters['relationships']['database'] = [
            'service' => 'mysqldb',
            'endpoint' => 'mysql',
        ];
    }
}
