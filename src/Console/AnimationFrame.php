<?php

namespace Platformsh\Cli\Console;

class AnimationFrame implements \Stringable
{
    public function __construct(private $content, private $duration = 50000)
    {
    }

    public function getDuration()
    {
        return $this->duration;
    }

    public function __toString(): string
    {
        return (string) $this->content;
    }
}
