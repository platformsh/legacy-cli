<?php

namespace Platformsh\Cli\Util;

use Platformsh\Cli\Helper\QuestionHelper;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Cli\Helper\ShellHelperInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RelationshipsUtil
{

    protected $output;
    protected $shellHelper;

    /**
     * @param OutputInterface $output
     * @param ShellHelperInterface $shellHelper
     */
    public function __construct(OutputInterface $output, ShellHelperInterface $shellHelper = null)
    {
        $this->output = $output;
        $this->shellHelper = $shellHelper ?: new ShellHelper($output);
    }

    /**
     * @param string          $sshUrl
     * @param InputInterface  $input
     *
     * @return array|false
     */
    public function chooseDatabase($sshUrl, InputInterface $input)
    {
        $relationships = $this->getRelationships($sshUrl);
        if (empty($relationships['database'])) {
            $this->output->writeln("No databases found");
            return false;
        }
        elseif (count($relationships['database']) > 1) {
            $questionHelper = new QuestionHelper($input, $this->output);
            $choices = [];
            foreach ($relationships['database'] as $key => $database) {
                $choices[$key] = $database['host'] . '/' . $database['path'];
            }
            $key = $questionHelper->choose($choices, 'Enter a number to choose a database');
            $database = $relationships['database'][$key];
        }
        else {
            $database = reset($relationships['database']);
        }

        return $database;
    }

    /**
     * @param string $sshUrl
     *
     * @return array
     */
    public function getRelationships($sshUrl)
    {
        $args = ['ssh', $sshUrl, 'echo $' . CLI_REMOTE_ENV_PREFIX . 'RELATIONSHIPS'];
        $result = $this->shellHelper->execute($args, null, true);

        return json_decode(base64_decode($result), true);
    }
}
