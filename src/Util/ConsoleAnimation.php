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
     * @param string[]        $frames
     *    The frames to display. Like any animation, this works best if the
     *    frames are all the same size.
     * @param int             $interval
     *    Minimum interval between frames in microseconds.
     */
    public function __construct(OutputInterface $output, array $frames, $interval = 500000)
    {
        $this->output = $output;
        $this->frames = $frames;
        $this->interval = $interval;
    }

    /**
     * Display the current frame, and advance the pointer to the next one.
     *
     * If the output is capable of using ANSI escape codes, this will attempt to
     * overwrite the previous frame. But if the output is not ANSI-compatible,
     * frames will be displayed one after the other. So, to display an endless
     * animation only where it's safe, use:
     *
     * <code>
     *     $animation = new ConsoleAnimation($output, $frames);
     *     do {
     *         $animation->render();
     *     } while ($output->isDecorated());
     * </code>
     */
    public function render()
    {
        // Ensure that at least $this->interval microseconds have passed since
        // the last frame.
        if ($this->lastFrameTime !== null) {
            $timeSince = (microtime(true) - $this->lastFrameTime) * 1000000;
            if ($timeSince < $this->interval) {
                usleep($this->interval - $timeSince);
            }
        }

        // Move the cursor up to overwrite the last frame.
        if ($this->lastFrame !== null && $this->output->isDecorated()) {
            $lastFrameHeight = substr_count($this->frames[$this->lastFrame], "\n") + 1;
            $this->output->write(sprintf("\033[%dA", $lastFrameHeight));
        }

        // Display the current frame.
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
