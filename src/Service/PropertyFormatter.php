<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Util\NestedArrayUtil;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class PropertyFormatter implements InputConfiguringInterface
{
    /** @var InputInterface|null */
    protected $input;

    /** @var \Platformsh\Cli\Service\Config */
    protected $config;

    public function __construct(InputInterface $input = null, Config $config = null)
    {
        $this->input = $input;
        $this->config = $config ?: new Config();
    }

    /**
     * @param mixed  $value
     * @param string $property
     *
     * @return string
     */
    public function format($value, $property = null)
    {
        if ($value === null && $property !== 'parent') {
            return '';
        }

        switch ($property) {
            case 'http_access':
                return $this->formatHttpAccess($value);

            case 'token':
                return '******';

            case 'addon_credentials':
                if (is_array($value) && isset($value['shared_secret'])) {
                    $value['shared_secret'] = '******';
                }
                break;

            case 'app_credentials':
                if (is_array($value) && isset($value['secret'])) {
                    $value['secret'] = '******';
                }
                break;

            case 'author.date':
            case 'committer.date':
            case 'created_at':
            case 'updated_at':
            case 'expires_at':
            case 'started_at':
            case 'completed_at':
            case 'granted_at':
            case 'ssl.expires_on':
                $value = $this->formatDate($value);
                break;

            case 'ssl':
                if ($property === 'ssl' && is_array($value) && isset($value['expires_on'])) {
                    $value['expires_on'] = $this->formatDate($value['expires_on']);
                }
                break;

            case 'permissions':
                $value = implode(', ', $value);
                break;

            case 'service_type':
                if (substr_count($value, ':') === 2) {
                    $value = substr($value, 0, strrpos($value, ':'));
                }
                break;
        }

        if (!is_string($value) && !is_float($value)) {
            $value = rtrim(Yaml::dump($value, 2));
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
        static $config;
        $config = $config ?: new Config();
        $definition->addOption(new InputOption(
            'date-fmt',
            null,
            InputOption::VALUE_REQUIRED,
            'The date format (as a PHP date format string)',
            $config->getWithDefault('application.date_format', 'c')
        ));
    }

    /**
     * Returns the configured date format.
     *
     * @return string
     */
    private function dateFormat()
    {
        if (isset($this->input) && $this->input->hasOption('date-fmt')) {
            return $this->input->getOption('date-fmt');
        }
        return $this->config->getWithDefault('application.date_format', 'c');
    }

    /**
     * Formats a string datetime.
     *
     * @param string $value
     *
     * @return string|null
     */
    public function formatDate($value)
    {
        // Workaround for the ssl.expires_on date, which is currently a
        // timestamp in milliseconds.
        if (substr($value, -3) === '000' && strlen($value) === 13) {
            $value = substr($value, 0, 10);
        }

        $timestamp = is_numeric($value) ? $value : strtotime($value);

        return $timestamp === false ? null : date($this->dateFormat(), $timestamp);
    }

    /**
     * Formats a UNIX timestamp.
     *
     * @param int $value
     *
     * @return string
     */
    public function formatUnixTimestamp($value)
    {
        return date($this->dateFormat(), $value);
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

    /**
     * Display a complex data structure.
     *
     * @param OutputInterface $output     An output object.
     * @param array           $data       The data to display.
     * @param string|null     $property   The property of the data to display
     *                                    (a dot-separated string).
     */
    public function displayData(OutputInterface $output, array $data, $property = null)
    {
        $key = null;

        if ($property) {
            $parents = explode('.', $property);
            $key = end($parents);
            $data = NestedArrayUtil::getNestedArrayValue($data, $parents, $keyExists);
            if (!$keyExists) {
                throw new \InvalidArgumentException('Property not found: ' . $property);
            }
        }

        if ($data === null) {
            return;
        }
        if (!is_string($data)) {
            $output->write(Yaml::dump($data, 5, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
        } else {
            $output->writeln($this->format($data, $key));
        }
    }
}
