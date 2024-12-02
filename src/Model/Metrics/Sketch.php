<?php

namespace Platformsh\Cli\Model\Metrics;

class Sketch
{
    /** @var string|int|float */
    private $sum;

    /** @var string|int|float */
    private $count;

    /** @var string */
    private $name;

    /**
     * @param array $value
     * @return Sketch
     */
    public static function fromApiValue(array $value): \Platformsh\Cli\Model\Metrics\Sketch
    {
        $s = new Sketch();
        $s->name = $value['info']['name'];
        $s->count = isset($value['value']['count']) ? $value['value']['count'] : 1;
        $s->sum = isset($value['value']['sum']) ? $value['value']['sum'] : 0;
        return $s;
    }

    /**
     * @return string
     */
    public function name()
    {
        return $this->name;
    }

    /**
     * @return bool
     */
    public function isInfinite(): bool
    {
        return $this->sum === 'Infinity' || $this->count === 'Infinity';
    }

    /**
     * @return float
     */
    public function average(): float
    {
        if ($this->isInfinite()) {
            throw new \RuntimeException('Cannot find the average of an infinite value');
        }
        return $this->sum / (float) $this->count;
    }
}
