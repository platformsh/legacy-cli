<?php

namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\NestedArrayUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class VariableDecodeCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('variable:decode')
            ->addArgument('value', InputArgument::REQUIRED, 'The variable value to decode')
            ->addOption('path', 'P', InputOption::VALUE_REQUIRED, 'The path to view within the variable')
            ->setDescription('Decode a complex environment variable');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $variable = $input->getArgument('value');

        $decoded = json_decode(base64_decode($variable, true), true);
        if (empty($decoded)) {
            $message = 'Failed to decode variable';
            if (json_last_error() !== JSON_ERROR_NONE) {
                $message .= ":\n" . json_last_error_msg();
            }
            throw new \RuntimeException($message);
        }

        if ($path = $input->getOption('path')) {
            $value = NestedArrayUtil::getNestedArrayValue($decoded, explode('.', $path), $keyExists);
            if (!$keyExists) {
                throw new \RuntimeException('Path not found: <error>' . $path . '</error>');
            }
        } else {
            $value = $decoded;
        }

        if (is_string($value)) {
            $output->writeln($value);
        } else {
            $output->writeln(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        return 0;
    }
}
