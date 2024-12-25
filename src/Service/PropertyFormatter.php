<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Util\NestedArrayUtil;
use Platformsh\Cli\Util\TimezoneUtil;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class PropertyFormatter implements InputConfiguringInterface
{
    protected Config $config;

    public function __construct(protected ?InputInterface $input = null, ?Config $config = null)
    {
        $this->config = $config ?: new Config();
    }

    public function format(mixed $value, ?string $property = null): string
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
                if (is_string($value) && substr_count($value, ':') === 2) {
                    [$stack, $version,] = explode(':', $value);
                    $value = $stack . ':' . $version;
                }
                break;
        }

        if (!is_string($value) && !is_float($value)) {
            $value = rtrim(Yaml::dump($value));
        }

        return (string) $value;
    }

    /**
     * Add options to a command's input definition.
     *
     * @param InputDefinition $definition
     */
    public static function configureInput(InputDefinition $definition): void
    {
        static $config;
        $config = $config ?: new Config();
        $definition->addOption(new InputOption(
            'date-fmt',
            null,
            InputOption::VALUE_REQUIRED,
            'The date format (as a PHP date format string)',
            $config->getStr('application.date_format'),
        ));
    }

    /**
     * Returns the configured date format.
     */
    private function dateFormat(): string
    {
        if (isset($this->input) && $this->input->hasOption('date-fmt')) {
            return $this->input->getOption('date-fmt');
        }
        return $this->config->getStr('application.date_format');
    }

    /**
     * Formats a string datetime.
     *
     * @param int|string $value
     *
     * @return string
     * @throws \Exception if the date is malformed.
     */
    public function formatDate(int|string $value): string
    {
        if (is_numeric($value)) {
            $dateTime = new \DateTime();
            $dateTime->setTimestamp((int) $value);
        } else {
            $dateTime = new \DateTime($value);
        }
        $dateTime->setTimezone(new \DateTimeZone(
            $this->config->getWithDefault('application.timezone', null)
            ?: TimezoneUtil::getTimezone(),
        ));

        return $dateTime->format($this->dateFormat());
    }

    /**
     * Formats a UNIX timestamp.
     */
    public function formatUnixTimestamp(int $value): string
    {
        return date($this->dateFormat(), $value);
    }

    /**
     * @param array<string, mixed>|string|null $httpAccess
     * @return string
     */
    protected function formatHttpAccess(array|string|null $httpAccess): string
    {
        $info = (array) $httpAccess;
        $info += [
            'addresses' => [],
            'basic_auth' => [],
            'is_enabled' => true,
        ];
        // Hide passwords.
        $info['basic_auth'] = array_map(fn(): string => '******', $info['basic_auth']);

        return $this->format($info);
    }

    /**
     * Displays a complex data structure.
     *
     * @param OutputInterface $output An output object.
     * @param array<string, mixed> $data The data to display.
     * @param string|null $property The property of the data to display
     *                              (a dot-separated string).
     */
    public function displayData(OutputInterface $output, array $data, ?string $property = null): void
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
