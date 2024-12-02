<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Application;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SubCommandRunner
{
    private readonly OutputInterface $stdErr;

    public function __construct(
        private readonly Application $application,
        private readonly InputInterface $input,
        private readonly OutputInterface $output,
    ) {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput(): $output;
    }

    /**
     * Runs another command in this CLI application.
     *
     * @param string $commandName
     * @param array $arguments
     * @param OutputInterface|null $output
     *
     * @return int
     * @throws ExceptionInterface
     */
    public function run(string $commandName, array $arguments = [], OutputInterface $output = null): int
    {
        $command = $this->application->find($commandName);

        $this->forwardStandardOptions($arguments, $this->input, $command->getDefinition());

        $cmdInput = new ArrayInput(['command' => $commandName] + $arguments);
        if (!empty($arguments['--yes']) || !empty($arguments['--no'])) {
            $cmdInput->setInteractive(false);
        } else {
            $cmdInput->setInteractive($this->input->isInteractive());
        }

        $this->stdErr->writeln(
            '<options=reverse>DEBUG</> Running sub-command: <info>' . $command->getName() . '</info>',
            OutputInterface::VERBOSITY_DEBUG
        );

        $currentCommand = $this->application->getCurrentCommand();
        $this->application->setCurrentCommand($command);

        try {
            $result = $command->run($cmdInput, $output ?: $this->output);
        } finally {
            $this->application->setCurrentCommand($currentCommand);
        }

        return $result;
    }

    /**
     * Forwards standard (unambiguous) arguments that a source and target command have in common.
     *
     * @param array &$args
     * @param InputInterface $input
     * @param InputDefinition $targetDef
     */
    private function forwardStandardOptions(array &$args, InputInterface $input, InputDefinition $targetDef): void
    {
        $stdOptions = [
            'no',
            'no-interaction',
            'yes',

            'no-wait',
            'wait',

            'org',
            'host',
            'project',
            'environment',
            'app',
            'worker',
            'instance',
        ];
        foreach ($stdOptions as $name) {
            if (!\array_key_exists('--' . $name, $args) && $targetDef->hasOption($name) && $input->hasOption($name)) {
                $value = $input->getOption($name);
                if ($value !== null && $value !== false) {
                    $args['--' . $name] = $value;
                }
            }
        }
    }
}
