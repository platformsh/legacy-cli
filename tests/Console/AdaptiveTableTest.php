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

    public function testWrapWithDecoration()
    {
        $example = 'Lorem ipsum <info>dolor</info> sit <info>amet,</info> consectetur <error>adipiscing elit,</error> sed do eiusmod tempor incididunt ut labore et dolore magna	 aliqua. Ut enim ad minim veniam, quis ☺ nostrud <options=underscore>exercitation</> ullamco laboris nisi ut aliquip ex ea commodo <options=reverse>consequat. 	   Duis</> aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat (cupidatat) non <info>proident</info>, sunt in culpa qui officia deserunt mollit anim id est laborum.';
        $exampleWrapped = <<<EOF
Lorem ipsum <info>dolor</info> sit <info>amet,</info> consectetur <error>adipiscing elit,</error> sed do eiusmod tempor
incididunt ut labore et dolore magna	 aliqua. Ut enim ad minim veniam, quis ☺
nostrud <options=underscore>exercitation</> ullamco laboris nisi ut aliquip ex ea commodo <options=reverse>consequat.</>
<options=reverse>Duis</> aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu
fugiat nulla pariatur. Excepteur sint occaecat (cupidatat) non <info>proident</info>, sunt in
culpa qui officia deserunt mollit anim id est laborum.
EOF;

        $table = new AdaptiveTable(new BufferedOutput());
        $result = $table->wrapWithDecoration($example, 80);
        $this->assertEquals($exampleWrapped, $result);
    }
}
