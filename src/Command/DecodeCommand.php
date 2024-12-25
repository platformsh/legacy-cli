<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Util\NestedArrayUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'decode', description: 'Decode a string that was encoded with JSON and Base64')]
class DecodeCommand extends CommandBase
{
    public function __construct(private readonly Config $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $envPrefix = $this->config->getStr('service.env_prefix');

        $this
            ->addArgument('value', InputArgument::REQUIRED, 'The value to decode')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The property to view within the value');

        $this->addExample(
            sprintf('View "foo" in %sVARIABLES', $envPrefix),
            sprintf('"$%sVARIABLES" -P foo', $envPrefix),
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $variable = $input->getArgument('value');
        if (trim((string) $variable) === '') {
            $this->stdErr->writeln('Failed to decode: the provided value is empty.');

            return 1;
        }

        $b64decoded = base64_decode((string) $variable, true);
        if ($b64decoded === false) {
            $this->stdErr->writeln('Invalid value: base64 decoding failed.');

            return 1;
        }

        $decoded = json_decode($b64decoded, true);
        if ($decoded === null) {
            $message = 'JSON decoding failed';
            if (json_last_error() !== JSON_ERROR_NONE) {
                $message .= ":\n" . json_last_error_msg();
            }
            $this->stdErr->writeln($message);

            return 1;
        }

        if ($property = $input->getOption('property')) {
            if (is_scalar($decoded)) {
                $this->stdErr->writeln('The --property option cannot be used with a scalar value.');

                return 1;
            } elseif (!is_array($decoded)) {
                $this->stdErr->writeln('The --property option cannot be used with a non-array value.');

                return 1;
            }
            if (array_key_exists($property, $decoded)) {
                $value = $decoded[$property];
            } else {
                $value = NestedArrayUtil::getNestedArrayValue($decoded, explode('.', (string) $property), $keyExists);
                if (!$keyExists) {
                    $this->stdErr->writeln('Property not found: <error>' . $property . '</error>');

                    return 1;
                }
            }
        } else {
            if ($decoded === [] && $b64decoded === '{}') {
                $decoded = new \stdClass();
            }

            $value = $decoded;
        }

        if (is_string($value)) {
            $output->writeln($value);
        } else {
            $output->writeln((string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return 0;
    }
}
