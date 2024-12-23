<?php

declare(strict_types=1);

namespace Platformsh\Cli\Util;

use Platformsh\Cli\Exception\InvalidConfigException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Tag\TaggedValue;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses a YAML file including Platform.sh's custom tags.
 */
class YamlParser
{
    /**
     * Parses a YAML file.
     *
     * @throws ParseException if the config could not be parsed
     * @throws \RuntimeException if the file cannot be read
     * @throws InvalidConfigException if the config is invalid
     *
     * @return TaggedValue|string|array<mixed>
     */
    public function parseFile(string $filename): TaggedValue|string|array
    {
        return $this->parseContent($this->readFile($filename), $filename);
    }

    /**
     * Parses a YAML string.
     *
     * @param string $content  The YAML content.
     * @param string $filename The filename where the content originated. This
     *                         is required for formatting useful error messages.
     *
     * @return TaggedValue|string|array<mixed>
     *
     * @throws ParseException if the config could not be parsed
     * @throws InvalidConfigException if the config is invalid
     */
    public function parseContent(string $content, string $filename): TaggedValue|string|array
    {
        $content = $this->cleanUp($content);
        try {
            $parsed = (new Yaml())->parse($content, Yaml::PARSE_CUSTOM_TAGS);
        } catch (ParseException $e) {
            throw new InvalidConfigException($e->getMessage(), $filename, '', $e);
        }

        return $this->processTags($parsed, $filename);
    }

    /**
     * Cleans up YAML to conform to the Symfony parser's expectations.
     */
    private function cleanUp(string $content): string
    {
        // If an entire file or snippet is indented, remove the indent.
        $trimmed = ltrim($content, "\r\n");
        if (strlen($trimmed) > 0 && ($trimmed[0] === "\t" || $trimmed[0] === ' ')) {
            $lines = preg_split('/\n|\r|\r\n/', $content);
            if (!$lines) {
                throw new \RuntimeException('Failed to split content by lines');
            }
            $indents = [];
            foreach ($lines as $line) {
                // Ignore blank lines.
                if (trim($line) === '') {
                    continue;
                }
                $indents[] = strlen($line) - strlen(ltrim($line, "\t "));
            }
            if (!empty($indents[0]) && $indents[0] === min($indents)) {
                foreach ($lines as &$line) {
                    $line = substr($line, $indents[0]);
                }
                $content = implode("\n", $lines);
            }
        }

        return $content;
    }

    /**
     * Reads a file and throws appropriate exceptions on failure.
     *
     * @throws \RuntimeException if the file cannot be found or read.
     */
    private function readFile(string $filename): string
    {
        if (!file_exists($filename)) {
            throw new \RuntimeException(sprintf('File not found: %s', $filename));
        }
        if (!is_readable($filename) || ($content = file_get_contents($filename)) === false) {
            throw new \RuntimeException(sprintf('Failed to read file: %s', $filename));
        }

        return $content;
    }

    /**
     * Recursively processes custom tags in the parsed config.
     *
     * @param TaggedValue|array<mixed> $config
     */
    private function processTags(mixed $config, string $filename): mixed
    {
        if ($config instanceof TaggedValue) {
            return $this->processSingleTag($config, $filename);
        }
        if (is_array($config)) {
            foreach ($config as $key => $item) {
                $config[$key] = $this->processTags($item, $filename);
            }
        }

        return $config;
    }

    /**
     * Processes a single config item, which may be a custom tag.
     *
     * @param TaggedValue $item
     * @param string $filename
     * @param string $configKey
     *
     * @return TaggedValue|string|array<string,mixed>|array<mixed>
     */
    private function processSingleTag(TaggedValue $item, string $filename, string $configKey = ''): TaggedValue|string|array
    {
        $tag = $item->getTag();
        $value = $item->getValue();

        // Process the '!include' tag. The '!archive' and '!file' tags are
        // ignored as they are not relevant to the CLI (yet).
        return match ($tag) {
            'include' => $this->resolveInclude($value, $filename, $configKey),
            default => $item,
        };
    }

    /**
     * Resolves an !include config tag value.
     *
     * @throws InvalidConfigException
     *
     * @return TaggedValue|string|array<mixed>
     */
    private function resolveInclude(mixed $value, string $filename, string $configKey = ''): TaggedValue|string|array
    {
        if (is_string($value)) {
            $includeType = 'yaml';
            $includePath = $value;
        } elseif (is_array($value)) {
            if ($missing = array_diff(['type', 'path'], array_keys($value))) {
                throw new InvalidConfigException('The !include tag is missing the key(s): ' . implode(', ', $missing), $filename, $configKey);
            }
            $includeType = $value['type'];
            $includePath = $value['path'];
        } else {
            throw new InvalidConfigException('The !include tag must be a string (for a YAML include), or an object containing "type" and "path".', $filename, $configKey);
        }
        $dir = dirname($filename);
        if (!$realDir = realpath($dir)) {
            throw new \RuntimeException('Failed to resolve directory: ' . $dir);
        }
        $includeFile = rtrim($realDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim((string) $includePath, DIRECTORY_SEPARATOR);

        try {
            return match ($includeType) {
                'archive', 'binary' => $value,
                'yaml' => $this->parseFile($includeFile),
                'string' => $this->readFile($includeFile),
                default => throw new InvalidConfigException(sprintf(
                    'Unrecognized !include tag type "%s"',
                    $includeType,
                ), $filename, $configKey),
            };
        } catch (\Exception $e) {
            if ($e instanceof InvalidConfigException) {
                throw $e;
            }
            throw new InvalidConfigException($e->getMessage(), $filename, $configKey);
        }
    }
}
