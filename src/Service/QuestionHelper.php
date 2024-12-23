<?php

declare(strict_types=1);

/**
 * @file
 * Overrides Symfony's QuestionHelper to provide --yes and --no options.
 */

namespace Platformsh\Cli\Service;

use Symfony\Component\Console\Helper\QuestionHelper as BaseQuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class QuestionHelper extends BaseQuestionHelper
{
    private readonly OutputInterface $output;

    /**
     * QuestionHelper constructor.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    public function __construct(private readonly InputInterface $input, OutputInterface $output)
    {
        if ($output instanceof ConsoleOutputInterface) {
            $output = $output->getErrorOutput();
        }
        $this->output = $output;
    }

    /**
     * Ask the user to confirm an action.
     *
     * @param string $questionText
     *   The text of the question to ask. This should normally include a question mark.
     * @param bool   $default
     *   The default answer. Warning: this should be left as true if the question is going to be used in non-interactive
     *   mode. This keeps consistent behavior for the --no-interaction and --yes (-y) options.
     *
     * @return bool
     */
    public function confirm(string $questionText, bool $default = true): bool
    {
        if (!$this->input->isInteractive() && !$default) {
            trigger_error(
                'A confirmation question is being asked with a default of "no" (false), despite being in non-interactive mode.'
                . ' So --yes will not pick the default result for this question, which is confusing.',
                E_USER_WARNING,
            );
        }

        $questionText .= ' <question>' . ($default ? '[Y/n]' : '[y/N]') . '</question> ';

        $yes = $this->input->hasOption('yes') && $this->input->getOption('yes');
        $no = $this->input->hasOption('no') && $this->input->getOption('no');
        if ($yes && !$no) {
            $this->output->writeln($questionText . 'y');
            return true;
        } elseif ($no && !$yes) {
            $this->output->writeln($questionText . 'n');
            return false;
        } elseif (!$this->input->isInteractive()) {
            $this->output->writeln($questionText . ($default ? 'y' : 'n'));
            return $default;
        }
        $question = new ConfirmationQuestion($questionText, $default);

        return $this->ask($this->input, $this->output, $question);
    }

    /**
     * Provides an interactive choice question.
     *
     * @param array<string, string> $items An associative array of choices.
     * @param string $text Some text to precede the choices.
     * @param string|null $default A default (as a key in $items).
     * @param bool $skipOnOne Whether to skip the choice if there is only one
     *                          item.
     *
     * @return string
     *   The chosen item (as a key in $items).
     *
     * @throws \RuntimeException on failure
     */
    public function choose(array $items, string $text = 'Enter a number to choose an item:', ?string $default = null, bool $skipOnOne = true): string
    {
        if (count($items) === 1) {
            if ($skipOnOne) {
                return key($items);
            } elseif ($default === null) {
                $default = key($items);
            }
        }
        $itemList = array_values($items);
        $defaultKey = $default !== null && isset($items[$default]) ? array_search($items[$default], $itemList, true) : null;

        $question = new ChoiceQuestion($text, $itemList, $defaultKey);
        $question->setMaxAttempts(5);

        // By default the ChoiceQuestion populates the autocomplete with the
        // array values. However this can mean values starting with a number
        // can autocomplete and prevent the user typing the index number.
        $question->setAutocompleterValues(array_merge(array_keys($itemList), array_values($items)));

        if (!$this->input->isInteractive()) {
            if (!isset($defaultKey)) {
                throw new \RuntimeException('A choice is needed, input is not interactive, and no default is available.');
            }
            $choice = $itemList[$defaultKey];
            $choiceKey = array_search($choice, $items, true);
            if ($choiceKey === false) {
                throw new \RuntimeException('Invalid default');
            }

            return $choiceKey;
        }

        $choice = $this->ask($this->input, $this->output, $question);
        $choiceKey = array_search($choice, $items, true);
        if ($choiceKey === false) {
            throw new \RuntimeException("Invalid value: $choice");
        }

        $this->output->writeln('');

        return $choiceKey;
    }

    /**
     * Provides an interactive choice question preserving the array keys.
     *
     * @param array<string, string> $items     An associative array of choices.
     * @param string $text      Some text to precede the choices.
     * @param string|null  $default   A default (as a key in $items).
     * @param bool $skipOnOne Whether to skip the choice if there is only one
     *                          item.
     * @param bool $newLine   Whether to output a newline after asking the question.
     *
     * @return string
     *   The chosen item (as a key in $items).
     *@throws \RuntimeException on failure
     *
     */
    public function chooseAssoc(array $items, string $text = 'Choose an item:', ?string $default = null, bool $skipOnOne = true, bool $newLine = true): string
    {
        if (count($items) === 1) {
            if ($skipOnOne) {
                return key($items);
            } elseif ($default === null) {
                $default = key($items);
            }
        }
        $question = new ChoiceQuestion($text, $items, $default);
        $question->setMaxAttempts(5);
        $choice = $this->ask($this->input, $this->output, $question);
        if ($newLine) {
            $this->output->writeln('');
        }
        return $choice;
    }

    /**
     * Ask a simple question which requires input.
     *
     * @param string $questionText
     * @param mixed|null $default
     * @param string[] $autoCompleterValues
     * @param callable|null $validator
     * @param string $defaultLabel
     * @return mixed
     *   The user's answer.
     */
    public function askInput(string $questionText, mixed $default = null, array $autoCompleterValues = [], ?callable $validator = null, string $defaultLabel = 'default: '): mixed
    {
        if ($default !== null) {
            $questionText .= sprintf(' (%s<question>%s</question>)', $defaultLabel, $default);
        }
        $questionText .= ': ';
        $question = new Question($questionText, $default);
        if (!empty($autoCompleterValues)) {
            $question->setAutocompleterValues($autoCompleterValues);
        }
        if ($validator !== null) {
            $question->setValidator($validator);
            $question->setMaxAttempts(5);
        }

        return $this->ask($this->input, $this->output, $question);
    }
}
