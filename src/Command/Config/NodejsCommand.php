<?php

namespace Platformsh\Cli\Command\Config;

use Platformsh\ConsoleForm\Field\OptionsField;

class NodejsCommand extends ConfigGenerateCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function getKey()
    {
        return 'nodejs';
    }

    /**
     * {@inheritdoc}
     */
    protected function getLabel()
    {
        return 'Generic Node.js';
    }

    /**
     * {@inheritdoc}
     */
    protected function getFields() {
        $fields['nodejs_version'] = new OptionsField('Node.js version', [
            'optionName' => 'nodejs-version',
            'options' => ['6.0', '7.5'],
            'default' => '7.5',
        ]);

        return $fields;
    }
}
