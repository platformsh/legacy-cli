<?php

namespace Platformsh\Cli\Util;

class PropertyFormatter
{

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

            case 'created_at':
            case 'updated_at':
                $value = $this->formatDate($value);
                break;
        }

        if (!is_string($value)) {
            $value = json_encode($value);
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
        return date('Y-m-d H:i:s T', strtotime($value));
    }

    /**
     * @param array|string|null $httpAccess
     *
     * @return string
     */
    protected function formatHttpAccess($httpAccess)
    {
        $info = (array) $httpAccess;
        $info += array('addresses' => array(), 'basic_auth' => array());
        // Hide passwords.
        $info['basic_auth'] = array_map(function () {
            return '******';
        }, $info['basic_auth']);

        return "Access: " . json_encode($info['addresses'])
        . "\nAuth: " . json_encode($info['basic_auth']);
    }
}
