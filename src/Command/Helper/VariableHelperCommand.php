<?php

namespace Platformsh\Cli\Command\Helper;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VariableHelperCommand extends HelperCommandBase
{
    protected function configure() {
        $this->setName('helper:variable')
            ->setDescription(sprintf('Extract a variable from %sVARIABLES', $this->config()->get('service.env_prefix')))
            ->addArgument('name', InputArgument::REQUIRED, 'The variable name');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $variables = $this->getArrayEnvVar('VARIABLES');
        $name = $input->getArgument('name');
        if (!array_key_exists($name, $variables)) {
            $this->stdErr->writeln('Variable not found: ' . $name);
            if (getenv(strtoupper($name)) !== false) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('A top-level environment variable exists named $' . strtoupper($name) . ' - perhaps you meant that?');
            } elseif (count($variables) > 0 && count($variables) < 100) {
                $this->stdErr->writeln('Defined variables:');
                $this->stdErr->writeln(array_keys($variables));
            }

            return 1;
        }

        $output->write(is_scalar($variables[$name]) ? $variables[$name] : json_encode($variables[$name], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return 0;
    }
}
