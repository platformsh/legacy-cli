<?php

declare(strict_types=1);

namespace Platformsh\Cli\Console;

use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableCellStyle;

/**
 * Extends the Symfony Console Table to make it adaptive to the terminal width.
 */
class AdaptiveTableCell extends TableCell
{
    protected bool $wrap = true;

    /**
     * @param string $value
     * @param array{rowspan?: int, colspan?: int, wrap?: bool, style?: ?TableCellStyle} $options
     */
    public function __construct(string $value, array $options = [])
    {
        if (isset($options['wrap'])) {
            $this->wrap = (bool) $options['wrap'];
            unset($options['wrap']);
        }

        parent::__construct($value, $options);
    }

    public function canWrap(): bool
    {
        return $this->wrap;
    }

    /**
     * Creates a new cell object based on this, with a new value.
     */
    public function withValue(string $value): self
    {
        $options = [
            'colspan' => $this->getColspan(),
            'rowspan' => $this->getRowspan(),
            'wrap' => $this->canWrap(),
            'style' => $this->getStyle(),
        ];

        return new self($value, $options);
    }
}
