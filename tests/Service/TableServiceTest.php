<?php

namespace Platformsh\Cli\Tests;

use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\StreamOutput;

class TableServiceTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Test a table filtered by allowed column names.
     */
    public function testColumns()
    {
        $stream = fopen('php://memory', 'rw');
        $output = new StreamOutput($stream);
        $definition = new InputDefinition();
        Table::configureInput($definition);
        $tableService = new Table(new ArrayInput([
            '--columns' => ['value 2,name'],
            '--format' => 'csv',
        ], $definition), $output);

        $rows = [
            ['foo', 1, 2, 3],
            ['bar', 4, 5, 6],
        ];
        $header = ['Name', 'Value 1', 'Value 2', 'Value 3'];
        $expected = "\"Value 2\",Name\n2,foo\n5,bar\n";

        $tableService->render($rows, $header);
        fseek($stream, 0);
        $actual = fread($stream, 1024);
        fclose($stream);

        $this->assertEquals($expected, $actual);
    }

    /**
     * Test that columns are validated.
     */
    public function testInvalidColumn()
    {
        $definition = new InputDefinition();
        Table::configureInput($definition);
        $tableService = new Table(new ArrayInput([
            '--columns' => ['value 2,name'],
            '--format' => 'csv',
        ], $definition), new NullOutput());

        $rows = [
            ['foo', 1, 3],
            ['bar', 4, 6],
        ];
        $header = ['Name', 'Value 1', 'Value 3'];

        $this->setExpectedException('InvalidArgumentException');
        $tableService->render($rows, $header);
    }
}
