<?php

namespace Platformsh\Cli\Util;

use Symfony\Component\Yaml\Yaml;

class PropertyFormatter
{
    /** @var int */
    public $yamlInline = 2;

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
                $value = $this->formatHttpAccess($value);
                break;

            case 'token':
                $value = '******';
                break;

            case 'created_at':
            case 'updated_at':
                $value = $this->formatDate($value);
                break;
        }

        if (!is_string($value)) {
            $value = rtrim(Yaml::dump($value, $this->yamlInline));
        }

        return $value;
    }

    /**
     * @param string $value
     *
     * @return string
     */
    protected function formatDate($value)
    {
        return date('r', strtotime($value));
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

        return "Enabled: " . json_encode($info['is_enabled'])
        . "\nAccess: " . json_encode($info['addresses'])
        . "\nAuth: " . json_encode($info['basic_auth']);
    }
}
