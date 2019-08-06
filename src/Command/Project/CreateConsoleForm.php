<?php

namespace Platformsh\Cli\Command\Project;

use Platformsh\ConsoleForm\Form;
use Platformsh\ConsoleForm\Exception\MissingValueException;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class CreateConsoleForm extends Form
{
    /**
     * {@inheritdoc}
     */
    public function resolveOptions(InputInterface $input, OutputInterface $output, QuestionHelper $helper)
    {
        $values = [];
        $stdErr = $output instanceof ConsoleOutput ? $output->getErrorOutput() : $output;
        foreach ($this->fields as $key => $field) {
            $field->onChange($values);

            //Check for the catalog flag.
            if ($field->getOptionName() == 'catalog_url' && $input->getOption('catalog')!==true) {
                continue;
            }
            //Check if the field should be initialized.
            if ($field->getOptionName() == 'initialize' && 
                ($input->getOption('catalog')!==true || $input->getOption('template')!==true)) {
                continue;
            }

            if (!$this->includeField($field, $values)) {
                continue;
            }

            // Get the value from the command-line options.
            $value = $field->getValueFromInput($input, false);
            if ($value !== null) {
                $field->validate($value);
            } elseif ($input->isInteractive()) {
                // Get the value interactively.
                $value = $helper->ask($input, $stdErr, $field->getAsQuestion());
                $stdErr->writeln('');
            } elseif ($field->isRequired()) {
                throw new MissingValueException('--' . $field->getOptionName() . ' is required');
            }

            self::setNestedArrayValue(
                $values,
                $field->getValueKeys() ?: [$key],
                $field->getFinalValue($value),
                true
            );
        }

        return $values;
    }
}
