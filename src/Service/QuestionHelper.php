<?php
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
    /** @var InputInterface */
    private $input;
    /** @var OutputInterface */
    private $output;

    /**
     * QuestionHelper constructor.
     *
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    public function __construct(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        if ($output instanceof ConsoleOutputInterface) {
            $output = $output->getErrorOutput();
        }
        $this->output = $output;
    }

    /**
     * Ask the user to confirm an action.
     *
     * @param string          $questionText
     * @param bool            $default
     *
     * @return bool
     */
    public function confirm($questionText, $default = true)
    {
        $questionText .= ' <question>' . ($default ? '[Y/n]' : '[y/N]') . '</question> ';

        $yes = $this->input->hasOption('yes') && $this->input->getOption('yes');
        $no = $this->input->hasOption('no') && $this->input->getOption('no');
        if ($yes && !$no) {
            $this->output->writeln($questionText . 'y');
            return true;
        } elseif ($no && !$yes) {
            $this->output->writeln($questionText . 'n');
            return false;
        }
        $question = new ConfirmationQuestion($questionText, $default);

        return $this->ask($this->input, $this->output, $question);
    }

    /**
     * @param array           $items   An associative array of choices.
     * @param string          $text    Some text to precede the choices.
     * @param mixed           $default A default (as a key in $items).
     *
     * @throws \RuntimeException on failure
     *
     * @return mixed
     *   The chosen item (as a key in $items).
     */
    public function choose(array $items, $text = 'Enter a number to choose an item:', $default = null)
    {
        if (count($items) === 1) {
            return key($items);
        }
        $itemList = array_values($items);
        $defaultKey = $default !== null ? array_search($default, $itemList) : null;
        $question = new ChoiceQuestion($text, $itemList, $defaultKey);
        $question->setMaxAttempts(5);

        // Unfortunately the default autocompletion can cause '2' to be
        // completed to '20', etc.
        $question->setAutocompleterValues(null);

        $choice = $this->ask($this->input, $this->output, $question);
        $choiceKey = array_search($choice, $items);
        if ($choiceKey === false) {
            throw new \RuntimeException("Invalid value: $choice");
        }

        return $choiceKey;
    }

    /**
     * Ask a simple question which requires input.
     *
     * @param string $questionText
     * @param mixed  $default
     * @param array  $autoCompleterValues
     *
     * @return string
     *   The user's answer.
     */
    public function askInput($questionText, $default = null, array $autoCompleterValues = [])
    {
        if ($default !== null) {
            $questionText .= ' <question>[' . $default . ']</question>';
        }
        $questionText .= ': ';
        $question = new Question($questionText, $default);
        if (!empty($autoCompleterValues)) {
            $question->setAutocompleterValues($autoCompleterValues);
        }

        return $this->ask($this->input, $this->output, $question);
    }
}
