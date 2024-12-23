<?php

namespace Platformsh\Cli\Model\Metrics;

readonly class Sketch
{
    private function __construct(private string|int|float|null $sum, private string|int|float $count, private string $name) {}

    /**
     * @param array{value: array<string, mixed>, info: array<string, mixed>} $value
     * @return self
     */
    public static function fromApiValue(array $value): self
    {
        return new Sketch(
            $value['value']['sum'] ?? null,
            $value['value']['count'] ?? 1,
            $value['info']['name'],
        );
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isInfinite(): bool
    {
        return $this->sum === 'Infinity' || $this->count === 'Infinity';
    }

    public function average(): float
    {
        if ($this->isInfinite()) {
            throw new \RuntimeException('Cannot find the average of an infinite value');
        }
        if ($this->sum === null) {
            return 0;
        }
        if (is_string($this->sum)) {
            throw new \RuntimeException('Cannot find the average of a string "sum": ' . $this->sum);
        }
        return $this->sum / (float) $this->count;
    }
}
