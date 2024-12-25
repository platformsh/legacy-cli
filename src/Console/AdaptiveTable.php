<?php

declare(strict_types=1);

namespace Platformsh\Cli\Console;

use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * Extends the Symfony Console Table to make it adaptive to the terminal width.
 */
class AdaptiveTable extends Table
{
    protected int $maxTableWidth;

    // The following 3 properties are copies of the private properties in the
    // parent Table class.
    /** @var array<array<int|string, string|int|float|TableCell>|TableSeparator> */
    protected array $rowsCopy = [];
    /** @var array<array<int|string, string|TableCell>> */
    protected array $headersCopy = [];

    /**
     * AdaptiveTable constructor.
     *
     * @param OutputInterface $outputCopy
     * @param int|null $maxTableWidth
     * @param int $minColumnWidth
     */
    public function __construct(protected OutputInterface $outputCopy, ?int $maxTableWidth = null, protected int $minColumnWidth = 10)
    {
        $this->maxTableWidth = $maxTableWidth !== null
            ? $maxTableWidth
            : (new Terminal())->getWidth();

        parent::__construct($this->outputCopy);
    }

    /**
     * {@inheritdoc}
     *
     * Overrides Table->addRow() so the row content can be accessed.
     *
     * @param TableSeparator|array<int|string, string|TableCell> $row
     */
    public function addRow(TableSeparator|array $row): static
    {
        if ($row instanceof TableSeparator) {
            $this->rowsCopy[] = $row;

            return parent::addRow($row);
        }

        $this->rowsCopy[] = array_values($row);

        return parent::addRow($row);
    }

    /**
     * {@inheritdoc}
     *
     * Overrides Table->setHeaders() so the header content can be accessed.
     *
     * @param array<mixed> $headers
     */
    public function setHeaders(array $headers): static
    {
        $headers = array_values($headers);
        if ($headers && !is_array($headers[0])) {
            $headers = [$headers];
        }

        $this->headersCopy = $headers;

        return parent::setHeaders($headers);
    }

    /**
     * {@inheritdoc}
     *
     * Overrides Table->render(), to adapt all the cells to the table width.
     */
    public function render(): void
    {
        $this->adaptRows();
        parent::render();
    }

    /**
     * Adapt rows based on the terminal width.
     */
    protected function adaptRows(): void
    {
        // Go through all headers and rows, wrapping their cells until each
        // column meets the max column width.
        $maxColumnWidths = $this->getMaxColumnWidths();
        $this->setRows($this->adaptCells($this->rowsCopy, $maxColumnWidths));
    }

    /**
     * Modify table rows, wrapping their cells' content to the max column width.
     *
     * @param array<array<int|string, string|int|float|TableCell>|TableSeparator> $rows
     * @param array<int|string, int> $maxColumnWidths
     *
     * @return array<array<int|string, string|int|float|TableCell>|TableSeparator>
     */
    protected function adaptCells(array $rows, array $maxColumnWidths): array
    {
        foreach ($rows as &$row) {
            if ($row instanceof TableSeparator) {
                continue;
            }
            foreach ($row as $column => &$cell) {
                $contents = (string) $cell;
                // Replace Windows line endings, because Symfony's buildTableRows() does not respect them.
                if (str_contains($contents, "\r\n")) {
                    $contents = \str_replace("\r\n", "\n", $contents);
                    if ($cell instanceof AdaptiveTableCell) {
                        $cell = $cell->withValue($contents);
                    } elseif (\is_string($cell)) {
                        $cell = $contents;
                    }
                }
                $cellWidth = $this->getCellWidth($cell);
                if ($cellWidth <= $maxColumnWidths[$column]) {
                    continue;
                }
                $wrapped = $this->wrapCell($contents, $maxColumnWidths[$column]);
                if ($cell instanceof TableCell) {
                    $cell = new TableCell($wrapped, [
                        'colspan' => $cell->getColspan(),
                        'rowspan' => $cell->getRowspan(),
                    ]);
                } elseif (is_string($cell)) {
                    $cell = $wrapped;
                }
            }
        }

        return $rows;
    }

    /**
     * Word-wraps the contents of a cell, so that they fit inside a max width.
     */
    private function wrapCell(string $contents, int $width): string
    {
        // Account for left-indented cells.
        if (str_starts_with($contents, ' ')) {
            $trimmed = ltrim($contents, ' ');
            $indentAmount = Helper::width($contents) - Helper::width($trimmed);
            $indent = str_repeat(' ', $indentAmount);

            return preg_replace('/^/m', $indent, $this->wrapWithDecoration($trimmed, $width - $indentAmount));
        }

        return $this->wrapWithDecoration($contents, $width);
    }

    /**
     * Word-wraps the contents of a cell, accounting for decoration.
     */
    public function wrapWithDecoration(string $formattedText, int $maxLength): string
    {
        $plainText = Helper::removeDecoration($this->outputCopy->getFormatter(), $formattedText);
        if ($plainText === $formattedText) {
            return wordwrap($plainText, $maxLength, "\n", true);
        }

        // Find all open and closing tags in the formatted text, with their
        // offsets, and build a plain text string out of the rest.
        $tagRegex = '[a-zA-Z][a-zA-Z0-9,_=;-]*+';
        preg_match_all('#</?(?:' . $tagRegex . ')?>#', $formattedText, $matches, PREG_OFFSET_CAPTURE);
        $plainText = '';
        $tagChunks = [];
        $lastTagClose = 0;
        foreach ($matches[0] as $match) {
            [$tagChunk, $tagOffset] = $match;
            if (substr($formattedText, $tagOffset - 1, 1) === '\\') {
                continue;
            }
            $plainText .= substr($formattedText, $lastTagClose, $tagOffset - $lastTagClose);
            $tagChunks[$tagOffset] = $tagChunk;
            $lastTagClose = $tagOffset + strlen($tagChunk);
        }
        $plainText .= substr($formattedText, $lastTagClose);

        // Wrap the plain text, keeping track of the removed characters in each
        // line (caused by trimming).
        $remaining = $plainText;
        $lines = [];
        $removedCharacters = [];
        while (!empty($remaining)) {
            if (strlen($remaining) > $maxLength) {
                $spacePos = strrpos(substr($remaining, 0, $maxLength + 1), ' ');
                if ($spacePos !== false) {
                    $breakPosition = $spacePos + 1;
                } else {
                    $breakPosition = $maxLength;
                    // Adjust for \< which will be converted to < later.
                    $breakPosition += substr_count($remaining, '\\<', 0, $breakPosition);
                }
                $line = substr($remaining, 0, $breakPosition);
                $remaining = substr($remaining, $breakPosition);
            } else {
                $line = $remaining;
                $remaining = '';
            }
            $lineTrimmed = trim($line);
            $removedCharacters[] = strlen($line) - strlen($lineTrimmed);
            $lines[] = $lineTrimmed;
        }

        // Interpolate the tags back into the wrapped text.
        $remainingTagChunks = $tagChunks;
        $lineOffset = 0;
        foreach ($lines as $lineNumber => &$line) {
            $lineLength = strlen($line) + $removedCharacters[$lineNumber];
            foreach ($remainingTagChunks as $tagOffset => $tagChunk) {
                // Prefer putting opening tags at the beginning of a line, not
                // the end.
                if ($tagChunk[1] !== '/' && $tagOffset === $lineOffset + $lineLength) {
                    continue;
                }
                if ($tagOffset >= $lineOffset && $tagOffset <= $lineOffset + $lineLength) {
                    $insertPosition = $tagOffset - $lineOffset;
                    $line = substr($line, 0, $insertPosition) . $tagChunk . substr($line, $insertPosition);
                    $lineLength += strlen($tagChunk);
                    unset($remainingTagChunks[$tagOffset]);
                }
            }
            $lineOffset += $lineLength;
        }

        $wrapped = implode("\n", $lines) . implode('', $remainingTagChunks);

        // Ensure that tags are closed at the end of each line and re-opened at
        // the beginning of the next one.
        $wrapped = preg_replace_callback('@(<' . $tagRegex . '>)(((?!(?<!\\\)</).)+)@s', fn(array $matches) => $matches[1] . str_replace("\n", "</>\n" . $matches[1], $matches[2]), $wrapped);

        return $wrapped;
    }

    /**
     * @return array<int|string, int>
     *   An array of the maximum column widths that fit into the table width,
     *   indexed by the column's key in the table's rows (a name or number).
     */
    protected function getMaxColumnWidths(): array
    {
        // Loop through the table rows and headers, building multidimensional
        // arrays of the 'original' and 'minimum' column widths. In the same
        // loop, build a count of the number of columns.
        $originalColumnWidths = [];
        $minColumnWidths = [];
        $columnCounts = [0];
        foreach (array_merge($this->rowsCopy, $this->headersCopy) as $rowNum => $row) {
            if ($row instanceof TableSeparator) {
                continue;
            }
            $columnCount = 0;
            foreach ($row as $column => $cell) {
                $columnCount += $cell instanceof TableCell ? $cell->getColspan() - 1 : 1;

                // The column width is the width of the widest cell.
                $cellWidth = $this->getCellWidth($cell);
                if (!isset($originalColumnWidths[$column]) || $originalColumnWidths[$column] < $cellWidth) {
                    $originalColumnWidths[$column] = $cellWidth;
                }

                // Find the minimum width of the cell. The default is configured
                // in minColumnWidth, but this is overridden for non-wrapping
                // cells and very narrow cells. Additionally, table headers are
                // never wrapped.
                $minCellWidth = $this->minColumnWidth;
                if ($cellWidth < $this->minColumnWidth
                    || ($cell instanceof AdaptiveTableCell && !$cell->canWrap())
                    || !isset($this->rowsCopy[$rowNum])) {
                    $minCellWidth = $cellWidth;
                }

                // The minimum column width is the greatest minimum cell width.
                if (!isset($minColumnWidths[$column]) || $minColumnWidths[$column] < $minCellWidth) {
                    $minColumnWidths[$column] = $minCellWidth;
                }
            }
            $columnCounts[] = $columnCount;
        }

        // Find the number of columns in the table. This uses the same process
        // as the parent private method Table->calculateNumberOfColumns().
        $columnCount = max($columnCounts);

        // Find the maximum width for each column's content, to fit into the
        // calculated maximum content width.
        $maxContentWidth = $this->getMaxContentWidth($columnCount);
        $maxColumnWidths = [];
        $totalWidth = array_sum($originalColumnWidths);
        asort($originalColumnWidths, SORT_NUMERIC);
        foreach ($originalColumnWidths as $column => $columnWidth) {
            $columnRatio = ($maxContentWidth / $totalWidth) * $columnWidth;
            $maxColumnWidth = (int) round($columnRatio);

            // Do not change the width of columns which are already narrower
            // than the minimum.
            if (isset($minColumnWidths[$column]) && $maxColumnWidth < $minColumnWidths[$column]) {
                $maxColumnWidth = $minColumnWidths[$column];
            }

            $maxColumnWidths[$column] = (int) $maxColumnWidth;
            $totalWidth -= $columnWidth;
            $maxContentWidth -= $maxColumnWidth;
        }

        return $maxColumnWidths;
    }

    /**
     * Find the maximum content width (excluding decoration) for each table row.
     *
     * @param int $columnCount
     *   The number of columns in the table.
     *
     * @return int|float
     *   The maximum table width, minus the width taken up by decoration.
     */
    protected function getMaxContentWidth(int $columnCount): int|float
    {
        $style = $this->getStyle();
        $verticalBorderQuantity = $columnCount + 1;
        $paddingQuantity = $columnCount * 2;

        return $this->maxTableWidth
            - $verticalBorderQuantity * strlen((string) $style->getBorderChars()[3])
            - $paddingQuantity * strlen($style->getPaddingChar());
    }

    /**
     * Get the default width of a table cell (the length of its longest line).
     *
     * This is inspired by Table->getCellWidth(), but this also accounts for
     * multi-line cells.
     *
     * @param mixed $cell
     *
     * @return float|int
     */
    private function getCellWidth(mixed $cell): int|float
    {
        $lineWidths = [0];
        $formatter = $this->outputCopy->getFormatter();
        foreach (explode(PHP_EOL, (string) $cell) as $line) {
            $lineWidths[] = Helper::width(Helper::removeDecoration($formatter, $line));
        }
        $cellWidth = max($lineWidths);
        if ($cell instanceof TableCell && $cell->getColspan() > 1) {
            $cellWidth /= $cell->getColspan();
        }

        return $cellWidth;
    }
}
