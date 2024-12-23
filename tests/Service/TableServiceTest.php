<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Service;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Util\Csv;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

class TableServiceTest extends TestCase
{
    /**
     * Test a table filtered by allowed column names.
     */
    public function testColumns(): void
    {
        $output = new BufferedOutput();
        $definition = new InputDefinition();
        Table::configureInput($definition);

        $input = new ArrayInput([], $definition);
        $tableService = new Table($input, $output);

        $header = ['Name', 'Value 1', 'value2' => 'Value 2', 'Value 3'];

        $input->setOption('columns', ['value*', 'name']);
        $expected = ['value 1', 'value2', 'value 3', 'name'];
        $this->assertEquals($expected, $tableService->columnsToDisplay($header));

        $input->setOption('columns', ['+value2']);
        $expected = ['name', 'value2'];
        $this->assertEquals($expected, $tableService->columnsToDisplay($header, ['name']));

        $input->setOption('columns', ['value2', 'name']);
        $expected = ['value2', 'name'];
        $this->assertEquals($expected, $tableService->columnsToDisplay($header));

        $rows = [
            ['foo', '1', '2', '3'],
            new TableSeparator(),
            ['bar', '4', '5', '6'],
        ];
        $expected = (new Csv(',', "\n"))->format([
            ['Value 2', 'Name'],
            ['2', 'foo'],
            ['5', 'bar'],
        ]);
        $input->setOption('format', 'csv');
        $tableService->render($rows, $header);
        $this->assertEquals($expected, $output->fetch());
    }

    /**
     * Test that columns are validated.
     */
    public function testInvalidColumn(): void
    {
        $definition = new InputDefinition();
        Table::configureInput($definition);
        $tableService = new Table(new ArrayInput([
            '--columns' => ['value 2,name'],
            '--format' => 'csv',
        ], $definition), new NullOutput());

        $header = ['Name', 'Value 1', 'Value 3'];

        $this->expectException(\InvalidArgumentException::class);
        $tableService->columnsToDisplay($header);
    }
}
