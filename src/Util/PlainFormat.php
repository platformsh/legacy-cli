<?php

namespace Platformsh\Cli\Util;

/**
 * A plain, line-based format intended for easy parsing on the command-line.
 *
 * This is similar to tab-separated values (TSV), but does not quote cells. It
 * therefore cannot preserve newlines or tabs within cells.
 */
class PlainFormat extends Csv
{
    public function __construct() {
        parent::__construct("\t", "\n");
    }

    /**
     * @inheritDoc
     */
    protected function formatCell($cell) {
        // Replace any newline or tab characters with a space.
        return \preg_replace('#[\r\n\t]+#', ' ', (string) $cell);
    }
}
