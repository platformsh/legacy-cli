<?php
/**
 * @file
 * Overrides Symfony's QuestionHelper to provide --yes and --no options.
 */

namespace CommerceGuys\Platform\Cli\Helper;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class PlatformQuestionHelper extends QuestionHelper
{

    /**
     * Ask the user to confirm an action.
     *
     * @param string $questionText
     * @param InputInterface $input
     * @param OutputInterface $output
     * @param bool $default
     *
     * @return bool
     */
    public function confirm($questionText, InputInterface $input, OutputInterface $output, $default = true)
    {
        $yes = $input->hasOption('yes') && $input->getOption('yes');
        $no = $input->hasOption('no') && $input->getOption('no');
        if ($yes && !$no) {
            return true;
        }
        elseif ($no && !$yes) {
            return false;
        }
        $questionText .= ' <question>' . ($default ? '[Y/n]' : '[y/N]') . '</question> ';
        $question = new ConfirmationQuestion($questionText, $default);
        return $this->ask($input, $output, $question);
    }

}
