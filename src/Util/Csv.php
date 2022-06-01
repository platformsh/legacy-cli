<?php

namespace Platformsh\Cli\Util;

class Csv
{
    private $delimiter;
    private $lineBreak;

    /**
     * Csv constructor.
     *
     * The default is to format CSV data according to RFC 4180, i.e. cells
     * are separated by commas, and enclosed if necessary with double quotes,
     * and lines are terminated by CRLF.
     *
     * @see https://tools.ietf.org/html/rfc4180
     *
     * @param string $delimiter The delimiter character between cells.
     * @param string $lineBreak The break character(s) between lines.
     */
    public function __construct($delimiter = ',', $lineBreak = "\r\n")
    {
        $this->delimiter = $delimiter;
        $this->lineBreak = $lineBreak;
    }

    /**
     * Format an array of rows as a CSV spreadsheet.
     *
     * @param array $data
     *   An array of rows. Each row is an array of cells (hopefully the same
     *   number in each row). Each cell must be a string, or a type that can
     *   be cast to a string.
     * @param bool  $appendLineBreak
     *   Whether to add a line break at the end of the final row.
     *
     * @return string
     */
    public function format(array $data, $appendLineBreak = true)
    {
        return implode($this->lineBreak, array_map([$this, 'formatRow'], $data))
            . ($appendLineBreak ? $this->lineBreak : '');
    }

    /**
     * Format an array as a CSV row.
     *
     * @param array $data
     *
     * @return string
     */
    private function formatRow(array $data)
    {
        return implode($this->delimiter, array_map([$this, 'formatCell'], $data));
    }

    /**
     * Format a CSV cell.
     *
     * @param string|object $cell
     *
     * @return string
     */
    protected function formatCell($cell)
    {
        // Cast cell data to a string.
        $cell = (string) $cell;

        // Enclose the cell in double quotes, if necessary.
        if (strpbrk($cell, '"' . $this->lineBreak . $this->delimiter) !== false) {
            $cell = '"' . str_replace('"', '""', $cell) . '"';
        }

        // Standardize line breaks.
        $cell = preg_replace('/\R/u', $this->lineBreak, $cell);

        return $cell;
    }
}
