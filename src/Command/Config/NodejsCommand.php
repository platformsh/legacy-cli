<?php

namespace Platformsh\Cli\Command\Config;

use Platformsh\ConsoleForm\Field\OptionsField;

class NodejsCommand extends ConfigGenerateCommandBase
{
    /**
     * {@inheritdoc}
     */
    public function getKey()
    {
        return 'nodejs';
    }

    /**
     * {@inheritdoc}
     */
    public function getLabel()
    {
        return 'Generic Node.js';
    }

    /**
     * {@inheritdoc}
     */
    public function getFields() {
            'options' => ['6.0', '7.5'],
            'default' => '7.5',
        $fields['runtime_version'] = new OptionsField('Node.js version', [
            'optionName' => 'runtime-version',
        ]);

        return $fields;
    }

    /**
     * {@inheritdoc}
     */
    public function alterParameters()
    {
        $this->parameters['runtime'] = 'nodejs';
    }


}
