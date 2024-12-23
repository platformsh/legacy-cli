<?php

declare(strict_types=1);

namespace Platformsh\Cli\Console;

readonly class AnimationFrame implements \Stringable
{
    public function __construct(private string $content, private int $duration = 50000) {}

    public function getDuration(): int
    {
        return $this->duration;
    }

    public function __toString(): string
    {
        return $this->content;
    }
}
