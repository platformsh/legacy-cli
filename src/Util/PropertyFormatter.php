<?php

namespace Platformsh\Cli\Util;

use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Yaml\Yaml;

class PropertyFormatter
{
    /** @var int */
    public $yamlInline = 2;

    /** @var InputInterface|null */
    protected $input;

    public function __construct(InputInterface $input = null)
    {
        $this->input = $input;
    }

    /**
     * @param mixed  $value
     * @param string $property
     *
     * @return string
     */
    public function format($value, $property = null)
    {
        switch ($property) {
            case 'http_access':
                return $this->formatHttpAccess($value);

            case 'token':
                return '******';

            case 'created_at':
            case 'updated_at':
                return $this->formatDate($value);
        }

        if (!is_string($value)) {
            $value = rtrim(Yaml::dump($value, $this->yamlInline));
        }

        return $value;
    }

    /**
     * Add options to a command's input definition.
     *
     * @param InputDefinition $definition
     */
    public static function configureInput(InputDefinition $definition)
    {
        $description = 'The date format (as a PHP date format string)';
        $option = new InputOption('date-fmt', null, InputOption::VALUE_REQUIRED, $description, 'r');
        $definition->addOption($option);
    }

    /**
     * @param string $value
     *
     * @return string
     */
    protected function formatDate($value)
    {
        $format = null;
        if (isset($this->input) && $this->input->hasOption('date-fmt')) {
            $format = $this->input->getOption('date-fmt');
        }

        return date($format ?: 'r', strtotime($value));
    }

    /**
     * @param array|string|null $httpAccess
     *
     * @return string
     */
    protected function formatHttpAccess($httpAccess)
    {
        $info = (array) $httpAccess;
        $info += [
            'addresses' => [],
            'basic_auth' => [],
            'is_enabled' => true,
        ];
        // Hide passwords.
        $info['basic_auth'] = array_map(function () {
            return '******';
        }, $info['basic_auth']);

        return $this->format($info);
    }
}
