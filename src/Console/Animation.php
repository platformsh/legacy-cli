<?php

namespace Platformsh\Cli\Console;

use Symfony\Component\Console\Output\OutputInterface;

class Animation
{
    protected $interval;

    /** @var \Platformsh\Cli\Console\AnimationFrame[] */
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
        $this->interval = $interval;

        foreach ($frames as &$frame) {
            if (is_string($frame)) {
                $frame = new AnimationFrame($frame, $interval);
            }
        }
        $this->frames = $frames;
    }

    /**
     * Display the current frame, and advance the pointer to the next one.
     *
     * If the output is capable of using ANSI escape codes, this will attempt to
     * overwrite the previous frame. But if the output is not ANSI-compatible,
     * this will display the $placeholder instead. So, to display an endless
     * animation only where it's safe, use:
     *
     * <code>
     *     $animation = new ConsoleAnimation($output, $frames);
     *     do {
     *         $animation->render();
     *     } while ($output->isDecorated());
     * </code>
     *
     * @param string $placeholder
     */
    public function render($placeholder = '.')
    {
        // Ensure that at least $this->interval microseconds have passed since
        // the last frame.
        if ($this->lastFrameTime !== null) {
            $timeSince = (microtime(true) - $this->lastFrameTime) * 1000000;
            $interval = $this->frames[$this->lastFrame]->getDuration();
            if ($timeSince < $interval) {
                usleep($interval - $timeSince);
            }
        }

        if ($this->lastFrame !== null) {
            // If overwriting is not possible, just output the placeholder.
            if (!$this->output->isDecorated()) {
                $this->output->write($placeholder);
                $this->lastFrameTime = microtime(true);
                return;
            }

            // Move the cursor up to overwrite the previous frame.
            $lastFrameHeight = substr_count($this->frames[$this->lastFrame]->__toString(), "\n") + 1;
            $this->output->write(sprintf("\033[%dA", $lastFrameHeight));
        }

        // Display the current frame.
        $this->output->writeln($this->frames[$this->currentFrame]->__toString());

        // Set up the next frame.
        $this->lastFrame = $this->currentFrame;
        $this->lastFrameTime = microtime(true);
        $this->currentFrame++;
        if (!isset($this->frames[$this->currentFrame])) {
            $this->currentFrame = 0;
        }
    }
}
