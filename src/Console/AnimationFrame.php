<?php

namespace Platformsh\Cli\Console;

class AnimationFrame
{
    private $content;
    private $duration = 500000;

    public function __construct($content, $duration = 50000)
    {
        $this->content = $content;
        $this->duration = $duration;
    }

    public function getDuration()
    {
        return $this->duration;
    }

    public function __toString()
    {
        return $this->content;
    }
}
