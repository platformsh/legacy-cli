<?php

namespace Platformsh\Cli\Util;

use Symfony\Component\Console\Output\OutputInterface;

class ConsoleAnimation
{
    protected $interval;
    protected $frames = [];
    protected $currentFrame = 0;
    protected $lastFrame;
    protected $output;
    protected $lastFrameTime;

    /**
     * @param OutputInterface $output
     * @param int             $interval Interval between frames in microseconds.
     * @param string[]        $frames
     */
    public function __construct(OutputInterface $output, $interval = 500000, array $frames)
    {
        $this->output = $output;
        $this->interval = $interval;
        $this->frames = $frames;
    }

    /**
     * @param bool $autoSleep
     */
    public function displayNext($autoSleep = true)
    {
        // Wait up to $this->interval μs before showing the next frame.
        if ($autoSleep && $this->lastFrameTime !== null) {
            $timeSince = (microtime(true) - $this->lastFrameTime) * 1000000;
            if ($timeSince < $this->interval) {
                usleep($this->interval - $timeSince);
            }
        }
        // Overwrite the last frame: move back an appropriate number of lines.
        if ($this->lastFrame !== null) {
            $lines = substr_count($this->frames[$this->lastFrame], "\n") + 1;
            $this->output->write(sprintf("\033[%dA", $lines));
        }
        // Display the new frame.
        $this->output->writeln($this->frames[$this->currentFrame]);
        // Set up the next frame.
        $this->lastFrame = $this->currentFrame;
        $this->lastFrameTime = microtime(true);
        $this->currentFrame++;
        if (!isset($this->frames[$this->currentFrame])) {
            $this->currentFrame = 0;
        }
   }
}
