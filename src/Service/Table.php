<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Console\AdaptiveTable;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Util\Csv;
use Platformsh\Cli\Util\PlainFormat;
use Platformsh\Cli\Util\Wildcard;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Display a table in a format chosen by the user.
 *
 * Usage:
 * <code>
 *     // Create a command property $tableHeader;
 *     private array $tableHeader = ['Column 1', 'Column 2', 'Column 3'];
 *
 *     // In a command's configure() method, add the --format and --columns options:
 *     Table::configureInput($this->getDefinition(), $this->tableHeader);
 *
 *     // In a command's execute() method, build and display the table:
 *     $table = new Table($input, $output);
 *     $rows = [
 *         ['Cell 1', 'Cell 2', 'Cell 3'],
 *         ['Cell 4', 'Cell 5', 'Cell 6'],
 *     ];
 *     $table->render($rows, $this->tableHeader);
 * </code>
 */
class Table implements InputConfiguringInterface
{
    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    public function __construct(protected InputInterface $input, protected OutputInterface $output) {}

    /**
     * Add the --format and --columns options to a command's input definition.
     *
     * @param InputDefinition $definition
     * @param array<string|int, string> $columns
     *   The table header or a list of available columns.
     * @param string[] $defaultColumns
     *   A list of default columns.
     */
    public static function configureInput(InputDefinition $definition, array $columns = [], array $defaultColumns = []): void
    {
        $description = 'The output format: table, csv, tsv, or plain';
        $option = new InputOption('format', null, InputOption::VALUE_REQUIRED, $description, 'table');
        $definition->addOption($option);
        $description = 'Columns to display.';
        if (!empty($columns)) {
            if (!empty($defaultColumns)) {
                $description .= "\n" . 'Available columns: ' . self::formatAvailableColumns($columns, $defaultColumns) . ' (* = default columns).';
                $description .= "\n" . 'The character "+" can be used as a placeholder for the default columns.';
            } else {
                $description .= "\n" . 'Available columns: ' . self::formatAvailableColumns($columns) . '.';
            }
        }
        $description .= "\n" . Wildcard::HELP . "\n" . ArrayArgument::SPLIT_HELP;
        $shortcut = $definition->hasShortcut('c') ? null : 'c';
        $option = new InputOption('columns', $shortcut, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, $description);
        $definition->addOption($option);
        $description = 'Do not output the table header';
        $option = new InputOption('no-header', null, InputOption::VALUE_NONE, $description);
        $definition->addOption($option);
    }

    /**
     * @param array<string|int, string> $columns
     * @param string[] $defaultColumns
     * @param bool $markDefault
     * @return string
     */
    private static function formatAvailableColumns(array $columns, array $defaultColumns = [], bool $markDefault = true): string
    {
        $columnNames = array_keys(self::availableColumns($columns));
        natcasesort($columnNames);
        if ($defaultColumns) {
            $defaultColumns = array_map('\strtolower', $defaultColumns);
            $columnNames = array_diff($columnNames, $defaultColumns);
            if ($markDefault) {
                $defaultColumns = array_map(fn($c): string => $c . '*', $defaultColumns);
            }
            $columnNames = array_merge($defaultColumns, $columnNames);
        }

        return implode(', ', $columnNames);
    }

    /**
     * Modifies the input to replace deprecated column names, and outputs a warning for each.
     *
     * @param array<string, string> $replacements
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return void
     */
    public function replaceDeprecatedColumns(array $replacements, InputInterface $input, OutputInterface $output): void
    {
        $stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $columns = $this->specifiedColumns();
        foreach ($replacements as $old => $new) {
            if (($pos = \array_search($old, $columns, true)) !== false) {
                $stdErr->writeln(\sprintf('<options=reverse>DEPRECATED</> The column <comment>%s</comment> has been replaced by <info>%s</info>.', $old, $new));
                $columns[$pos] = $new;
            }
        }
        $input->setOption('columns', $columns);
    }

    /**
     * Modifies the input to remove deprecated columns, and outputs a warning for each.
     *
     * @param string[] $remove A list of column names to remove.
     * @param string $placeholder The name of a placeholder column to display in place of the removed one.
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return void
     */
    public function removeDeprecatedColumns(array $remove, string $placeholder, InputInterface $input, OutputInterface $output): void
    {
        $stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $columns = $this->specifiedColumns();
        foreach ($remove as $name) {
            if (($pos = \array_search($name, $columns, true)) !== false) {
                $stdErr->writeln(\sprintf('<options=reverse>DEPRECATED</> The column <comment>%s</comment> has been removed.', $name));
                $columns[$pos] = $placeholder;
            }
        }
        $input->setOption('columns', $columns);
    }

    /**
     * Render an single-dimensional array of values, with their property names.
     *
     * @param array<int|string, string|TableCell> $values
     * @param array<int|string, string|TableCell> $propertyNames
     */
    public function renderSimple(array $values, array $propertyNames): void
    {
        $data = [];
        foreach ($propertyNames as $key => $label) {
            $data[] = [$label, $values[$key]];
        }
        $this->render($data, ['Property', 'Value']);
    }

    /**
     * Returns the columns to display, based on defaults and user input.
     *
     * @param array<int|string, string|TableCell> $header
     * @param string[] $defaultColumns
     * @return string[] A list of (lower-case) column names.
     */
    public function columnsToDisplay(array $header, array $defaultColumns = []): array
    {
        $availableColumns = array_keys(self::availableColumns($header));
        if (empty($defaultColumns)) {
            $defaultColumns = $availableColumns;
        } else {
            $defaultColumns = array_map('strtolower', $defaultColumns);
        }

        $specifiedColumns = $this->specifiedColumns();
        if (empty($specifiedColumns)) {
            return $defaultColumns;
        }

        $requestedCols = [];
        foreach ($specifiedColumns as $specifiedColumn) {
            // A plus is a placeholder for the set of default columns.
            // It can be a name on its own or next to another name.
            if ($specifiedColumn === '+') {
                $requestedCols = \array_merge($requestedCols, $defaultColumns);
            } else {
                $requestedCols[] = \strtolower($specifiedColumn);
            }
        }

        $toDisplay = [];
        foreach ($requestedCols as $requestedCol) {
            $matched = Wildcard::select($availableColumns, [$requestedCol]);
            if (empty($matched)) {
                throw new InvalidArgumentException(
                    sprintf('Column not found: %s (available columns: %s)', $requestedCol, self::formatAvailableColumns($availableColumns)),
                );
            }
            $toDisplay = array_merge($toDisplay, $matched);
        }

        return \array_unique($toDisplay);
    }

    /**
     * Render a table of data to output.
     *
     * @param array<array<int|string, string|int|float|TableCell>|TableSeparator> $rows
     *   The table rows.
     * @param array<int|string, string|TableCell> $header
     *   The table header (optional).
     * @param string[] $defaultColumns
     *   Default columns to display (optional). Columns are identified by
     *   their name in $header, or alternatively by their key in $rows.
     */
    public function render(array $rows, array $header = [], array $defaultColumns = []): void
    {
        $format = $this->getFormat();

        $columnsToDisplay = $this->columnsToDisplay($header, $defaultColumns);
        $rows = $this->filterColumns($rows, $header, $columnsToDisplay);

        if ($this->input->hasOption('no-header') && $this->input->getOption('no-header')) {
            $header = [];
        } else {
            /** @var array<int|string, string|TableCell> $header */
            $header = $this->filterColumns([0 => $header], $header, $columnsToDisplay)[0];
        }

        switch ($format) {
            case 'csv':
                $this->renderCsv($rows, $header);
                break;

            case 'tsv':
                $this->renderCsv($rows, $header, "\t");
                break;

            case 'plain':
                $this->renderPlain($rows, $header);
                break;

            case null:
            case 'table':
                $this->renderTable($rows, $header);
                break;

            default:
                throw new InvalidArgumentException(sprintf('Invalid format: "%s". Supported formats: table, csv, tsv, plain', $format));
        }
    }

    /**
     * Find whether the user wants machine-readable output.
     *
     * @return bool
     *   True if the user has specified a machine-readable format via the
     *   --format option (e.g. 'csv' or 'tsv'), false otherwise.
     */
    public function formatIsMachineReadable(): bool
    {
        return in_array($this->getFormat(), ['csv', 'tsv', 'plain']);
    }

    /**
     * Get the columns specified by the user.
     *
     * @return string[]
     */
    protected function specifiedColumns(): array
    {
        if (!$this->input->hasOption('columns')) {
            return [];
        }
        $val = $this->input->getOption('columns');
        if (\count($val) === 1) {
            $first = \reset($val);
            if (str_contains((string) $first, '+')) {
                $first = preg_replace('/([\w%])\+/', '$1,+', (string) $first);
                $first = preg_replace('/\+([\w%])/', '+,$1', (string) $first);
                $val = [$first];
            }
        }
        return ArrayArgument::split($val);
    }

    /**
     * Returns the available columns, which are all the (lower-cased) values and string keys in the header.
     *
     * @param array<int|string, string|TableCell> $header
     * @return array<string, string|int>
     */
    private static function availableColumns(array $header): array
    {
        $availableColumns = [];
        foreach ($header as $key => $column) {
            $columnName = \is_string($key) ? $key : $column;
            $availableColumns[\strtolower((string) $columnName)] = $key;
        }
        return $availableColumns;
    }

    /**
     * Filter rows by column names.
     *
     * @param array<array<int|string, string|int|float|TableCell>|TableSeparator> $rows
     * @param array<int|string, string|TableCell> $header
     * @param string[] $columnsToDisplay
     *
     * @return array<array<int|string, string|int|float|TableCell>|TableSeparator>
     */
    private function filterColumns(array $rows, array $header, array $columnsToDisplay): array
    {
        if (empty($columnsToDisplay)) {
            return $rows;
        }

        $availableColumns = self::availableColumns($header);

        // Filter the rows for keys matching those in $availableColumns. If a
        // key doesn't exist in a row, then the cell will be an empty string.
        $newRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                // Ignore non-arrays such as TableSeparator objects.
                $newRows[] = $row;
                continue;
            }
            $newRow = [];
            foreach ($columnsToDisplay as $columnNameLowered) {
                $keyFromHeader = $availableColumns[$columnNameLowered] ?? false;
                if ($keyFromHeader !== false && array_key_exists($keyFromHeader, $row)) {
                    $newRow[] = $row[$keyFromHeader];
                    continue;
                }
                $numericKey = array_search($columnNameLowered, array_keys($availableColumns), true);
                if ($numericKey !== false && array_key_exists($numericKey, $row)) {
                    $newRow[] = $row[$numericKey];
                } else {
                    $newRow[] = '';
                }
            }
            $newRows[] = $newRow;
        }

        return $newRows;
    }

    /**
     * Get the user-specified format.
     *
     * @return string|null
     */
    protected function getFormat(): ?string
    {
        if ($this->input->hasOption('format') && $this->input->getOption('format')) {
            return strtolower((string) $this->input->getOption('format'));
        }

        return null;
    }

    /**
     * Renders CSV output.
     *
     * @param array<array<int|string, string|int|float|TableCell>|TableSeparator> $rows
     * @param array<int|string, string|TableCell> $header
     */
    protected function renderCsv(array $rows, array $header, string $delimiter = ','): void
    {
        if (!empty($header)) {
            array_unshift($rows, $header);
        }
        // Remove TableSeparator objects.
        $rows = array_filter($rows, '\\is_array');
        // RFC 4180 (the closest thing to a CSV standard) asks for CRLF line
        // breaks, but these do not play nicely with POSIX shells whose
        // default internal field separator (IFS) does not account for CR. So
        // the line break character is forced as LF.
        $this->output->write((new Csv($delimiter, "\n"))->format($rows));
    }

    /**
     * Render plain, line-based output.
     *
     * @param array<array<int|string, string|int|float|TableCell>|TableSeparator> $rows
     * @param array<int|string, string|TableCell> $header
     */
    protected function renderPlain(array $rows, array $header): void
    {
        if (!empty($header)) {
            array_unshift($rows, $header);
        }
        // Remove TableSeparator objects.
        $rows = array_filter($rows, '\\is_array');
        $this->output->write((new PlainFormat())->format($rows));
    }

    /**
     * Render a Symfony Console table.
     *
     * @param array<array<int|string, string|int|float|TableCell>|TableSeparator> $rows
     * @param array<int|string, string|TableCell> $header
     */
    protected function renderTable(array $rows, array $header): void
    {
        $table = new AdaptiveTable($this->output);
        $table->setHeaders($header);
        $table->setRows($rows);
        $table->render();
    }
}
