<?php

namespace CommerceGuys\Platform\Cli\Helper;

use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Process\Exception\ProcessFailedException;

class ShellHelper extends Helper implements ShellHelperInterface {

    /** @var OutputInterface|null */
    protected $output;

    /** @var string */
    protected $directory;

    public function getName()
    {
        return 'shell';
    }

    /**
     * @inheritdoc
     */
    public function setOutput(OutputInterface $output = null)
    {
        $this->output = $output;
    }

    /**
     * @inheritdoc
     */
    public function setWorkingDirectory($dir)
    {
        $this->directory = $dir;
    }

    /**
     * @inheritdoc
     */
    public function log($level, $message)
    {
        if ($this->output) {
            $this->output->write($message);
        }
    }

    /**
     * @inheritdoc
     */
    public function execute(array $args, $mustRun = false)
    {
        $builder = new ProcessBuilder($args);
        $process = $builder->getProcess();
        if ($this->directory) {
            $process->setWorkingDirectory($this->directory);
        }
        try {
            $process->mustRun(array($this, 'log'));
        } catch (ProcessFailedException $e) {
            if (!$mustRun) {
                return false;
            }
            throw $e;
        }
        $output = $process->getOutput();

        return $output ? rtrim($output) : true;
    }
}
