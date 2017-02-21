<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Console\AdaptiveTable;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Display a table in a format chosen by the user.
 *
 * Usage:
 * <code>
 *     // In a command's configure() method, add the --format option:
 *     Table::addFormatOption($this->getDefinition());
 *
 *     // In a command's execute() method, build and display the table:
 *     $table = new Table($input, $output);
 *     $header = ['Column 1', 'Column 2', 'Column 3'];
 *     $rows = [
 *         ['Cell 1', 'Cell 2', 'Cell 3'],
 *         ['Cell 4', 'Cell 5', 'Cell 6'],
 *     ];
 *     $table->render($rows, $header);
 * </code>
 */
class Table implements InputConfiguringInterface
{
    protected $output;
    protected $input;

    /**
     * @param InputInterface  $input
     * @param OutputInterface $output
     */
    public function __construct(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->input = $input;
    }

    /**
     * Add the --format option to a command's input definition.
     *
     * @param InputDefinition $definition
     */
    public static function configureInput(InputDefinition $definition)
    {
        $description = 'The output format ("table", "csv", or "tsv")';
        $option = new InputOption('format', null, InputOption::VALUE_REQUIRED, $description, 'table');
        $definition->addOption($option);
    }

    /**
     * Render an single-dimensional array of values, with their property names.
     *
     * @param string[] $values
     * @param string[] $propertyNames
     */
    public function renderSimple(array $values, array $propertyNames)
    {
        $data = [];
        foreach ($propertyNames as $key => $label) {
            $data[] = [$label, $values[$key]];
        }
        $this->render($data, ['Property', 'Value']);
    }

    /**
     * Render a table of data to output.
     *
     * @param array $rows
     *   The table rows.
     * @param string[] $header
     *   The table header (optional).
     */
    public function render(array $rows, array $header = [])
    {
        $format = $this->getFormat();

        switch ($format) {
            case 'csv':
                $this->renderCsv($rows, $header);
                break;

            case 'tsv':
                $this->renderCsv($rows, $header, "\t");
                break;

            case null:
            case 'table':
                $this->renderTable($rows, $header);
                break;

            default:
                throw new \InvalidArgumentException(sprintf('Invalid format: %s', $format));
        }
    }

    /**
     * Find whether the user wants machine-readable output.
     *
     * @return bool
     *   True if the user has specified a machine-readable format via the
     *   --format option (e.g. 'csv' or 'tsv'), false otherwise.
     */
    public function formatIsMachineReadable()
    {
        return in_array($this->getFormat(), ['csv', 'tsv']);
    }

    /**
     * Get the user-specified format.
     *
     * @return string|null
     */
    protected function getFormat()
    {
        if ($this->input->hasOption('format') && $this->input->getOption('format')) {
            return strtolower($this->input->getOption('format'));
        }

        return null;
    }

    /**
     * Render CSV output.
     *
     * @param array  $rows
     * @param array  $header
     * @param string $delimiter
     * @param string $enclosure
     */
    protected function renderCsv($rows, $header, $delimiter = ',', $enclosure = '"')
    {
        if ($this->output instanceof StreamOutput) {
            $stream = $this->output->getStream();
        } else {
            throw new \RuntimeException('A stream output is required for the CSV format');
        }
        if ($header) {
            fputcsv($stream, $header, $delimiter, $enclosure);
        }
        foreach ($rows as $row) {
            fputcsv($stream, $row, $delimiter, $enclosure);
        }
    }

    /**
     * Render a Symfony Console table.
     *
     * @param array $rows
     * @param array $header
     */
    protected function renderTable(array $rows, array $header)
    {
        $table = new AdaptiveTable($this->output);
        $table->setHeaders($header);
        $table->setRows($rows);
        $table->render();
    }
}
