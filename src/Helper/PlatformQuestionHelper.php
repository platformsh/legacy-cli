<?php
/**
 * @file
 * Overrides Symfony's QuestionHelper to provide --yes and --no options.
 */

namespace CommerceGuys\Platform\Cli\Helper;

use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class PlatformQuestionHelper extends QuestionHelper
{
    /**
     * {@inheritdoc}
     *
     * @return string|bool  The user's answer.
     */
    public function ask(InputInterface $input, OutputInterface $output, Question $question)
    {
        if ($question instanceof ConfirmationQuestion) {
            $yes = $input->hasOption('yes') && $input->getOption('yes');
            $no = $input->hasOption('no') && $input->getOption('no');
            if ($yes && !$no) {
                return true;
            }
            elseif ($no && !$yes) {
                return false;
            }
        }
        return parent::ask($input, $output, $question);
    }
}
