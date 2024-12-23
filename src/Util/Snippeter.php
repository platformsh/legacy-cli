<?php

declare(strict_types=1);

namespace Platformsh\Cli\Util;

/**
 * Allows inserting code snippets into larger DSL files (e.g. a shell config file).
 */
class Snippeter
{
    /**
     * Modifies file contents to include a new or updated snippet.
     *
     * @param string $fileContents
     *   The current file contents.
     * @param string $snippet
     *   The new snippet that should be inserted. Pass an empty string to
     *   delete the existing snippet.
     * @param string $begin
     *   A delimiter for the beginning of the snippet, including comment
     *   syntax, for example '# BEGIN'.
     * @param string $end
     *   A delimiter for the end of the snippet, including comment syntax, for
     *   example '# END'.
     * @param string|null $beginPattern
     *   A regular expression pattern that loosely matches the $begin string,
     *   in case of changes.
     *
     * @return string The new file contents.
     */
    public function updateSnippet(string $fileContents, string $snippet, string $begin, string $end, ?string $beginPattern = null): string
    {
        // Look for the position of the $begin string in the current config.
        $beginPos = strpos($fileContents, $begin);

        // Otherwise, look for a line that loosely matches the $begin string.
        if ($beginPos === false && $beginPattern !== null) {
            if (preg_match($beginPattern, $fileContents, $matches, PREG_OFFSET_CAPTURE)) {
                $beginPos = $matches[0][1];
            }
        }

        // Find the snippet's end: the first occurrence of $end after $begin.
        $endPos = false;
        if ($beginPos !== false) {
            $endPos = strpos($fileContents, $end, $beginPos);
        }

        $found = $beginPos !== false && $endPos !== false && $endPos > $beginPos;

        // If nothing existing was found and there is nothing to insert,
        // return the original contents.
        if (!$found && $snippet === '') {
            return $fileContents;
        }

        // If an existing snippet has been found, update it.
        if ($found) {
            if ($snippet !== '') {
                $insert = $begin . $snippet . $end;
            } else {
                $insert = '';
            }

            return substr_replace(
                $fileContents,
                $insert,
                $beginPos,
                $endPos + strlen($end) - $beginPos,
            );
        }

        // Otherwise, add a new snippet to the end of the file.
        $output = rtrim($fileContents, PHP_EOL);
        if (strlen($output)) {
            $output .= PHP_EOL . PHP_EOL;
        }
        $output .= $begin . $snippet . $end . PHP_EOL;

        return $output;
    }
}
