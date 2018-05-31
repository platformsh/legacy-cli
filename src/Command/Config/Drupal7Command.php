<?php

namespace Platformsh\Cli\Command\Config;

class Drupal7Command extends PhpCommand implements ConfigGenerateInterface
{
    /**
     * {@inheritdoc}
     */
    public function getKey()
    {
        return 'drupal7';
    }

    /**
     * {@inheritdoc}
     */
    public function getLabel()
    {
        return 'Drupal 7';
    }

    /**
     * {@inheritdoc}
     */
    public function getFields()
    {
        $commonFields = parent::getFields();

        $fields['php_version'] = $commonFields['php_version'];
        $fields['webroot'] = $commonFields['webroot']
            ->set('default', 'public');

        return $fields;
    }

    /**
     * {@inheritdoc}
     */
    public function alterParameters()
    {
        $this->parameters['services']['mysqldb'] = [
            'type' => 'mysql:10.0',
            'disk' => 2048,
        ];
        $parameters['relationships']['database'] = 'mysqldb:mysql';
    }
}
