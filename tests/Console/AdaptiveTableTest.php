<?php

namespace Platformsh\Cli\Tests;

use Platformsh\Cli\Console\AdaptiveTable;
use Symfony\Component\Console\Output\BufferedOutput;

class AdaptiveTableTest extends \PHPUnit_Framework_TestCase
{
    /**
     * Test that a wide table is adapted to a maximum width.
     */
    public function testAdaptedRowsFitMaxTableWidth()
    {
        $maxTableWidth = 60;
        $buffer = new BufferedOutput();
        $table = new AdaptiveTable($buffer, $maxTableWidth);
        $table->setHeaders([
            ['Row', 'Lorem', 'ipsum', 'dolor', 'sit'],
        ]);
        $table->setRows([
            ['#1', 'amet', 'consectetur', 'adipiscing elit', 'Quisque pulvinar'],
            ['#2', 'tellus sit amet', 'sollicitudin', 'tincidunt', 'risus'],
            ['#3', 'risus', 'sem', 'mattis', 'ex'],
            ['#4', 'quis', 'luctus metus', 'lorem cursus', 'ligula'],
        ]);
        $table->render();

        // Test that the table fits into the maximum width.
        $lineWidths = [];
        foreach (explode(PHP_EOL, $buffer->fetch()) as $line) {
            $lineWidths[] = strlen($line);
        }
        $this->assertLessThanOrEqual($maxTableWidth, max($lineWidths));
    }
}
