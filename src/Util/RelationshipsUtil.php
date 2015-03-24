<?php

namespace Platformsh\Cli\Util;

use Platformsh\Cli\Helper\PlatformQuestionHelper;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Cli\Helper\ShellHelperInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class RelationshipsUtil
{

    protected $shellHelper;

    /**
     * @param ShellHelperInterface $shellHelper
     */
    public function __construct(ShellHelperInterface $shellHelper = null)
    {
        $this->shellHelper = $shellHelper ?: new ShellHelper();
    }

    /**
     * @param string          $sshUrl
     * @param InputInterface  $input
     * @param OutputInterface $output
     *
     * @return array|false
     */
    public function chooseDatabase($sshUrl, InputInterface $input, OutputInterface $output)
    {
        $relationships = $this->getRelationships($sshUrl);
        if (empty($relationships['database'])) {
            $output->writeln("No databases found");
            return false;
        }
        elseif (count($relationships['database']) > 1) {
            $questionHelper = new PlatformQuestionHelper();
            $choices = array();
            foreach ($relationships['database'] as $key => $database) {
                $choices[$key] = $database['host'] . '/' . $database['path'];
            }
            $key = $questionHelper->choose($choices, 'Enter a number to choose a database', $input, $output);
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
        $args = array('ssh', $sshUrl, 'echo $PLATFORM_RELATIONSHIPS');
        $result = $this->shellHelper->execute($args, null, true);

        return json_decode(base64_decode($result), true);
    }
}
