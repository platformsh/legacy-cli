<?php

namespace Platformsh\Cli\Tests\Console;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Console\AdaptiveTable;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\BufferedOutput;

class AdaptiveTableTest extends TestCase
{
    /**
     * Test that a wide table is adapted to a maximum width.
     */
    public function testAdaptedRowsFitMaxTableWidth(): void
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
            new TableSeparator(),
            ['#3', 'risus', 'sem', 'mattis', 'ex'],
            ['#4', 'quis', 'luctus metus', 'lorem cursus', 'ligula'],
        ]);
        $table->render();
        $result = $buffer->fetch();

        // Test that the table fits into the maximum width.
        $lineWidths = [];
        foreach (explode(PHP_EOL, $result) as $line) {
            $lineWidths[] = strlen($line);
        }
        $this->assertLessThanOrEqual($maxTableWidth, max($lineWidths));

        $expected = <<<'EOT'
+-----+------------+------------+------------+----------+
| Row | Lorem      | ipsum      | dolor      | sit      |
+-----+------------+------------+------------+----------+
| #1  | amet       | consectetu | adipiscing | Quisque  |
|     |            | r          | elit       | pulvinar |
| #2  | tellus sit | sollicitud | tincidunt  | risus    |
|     | amet       | in         |            |          |
+-----+------------+------------+------------+----------+
| #3  | risus      | sem        | mattis     | ex       |
| #4  | quis       | luctus     | lorem      | ligula   |
|     |            | metus      | cursus     |          |
+-----+------------+------------+------------+----------+

EOT;
        $this->assertEquals($expected, $result);
    }

    /**
     * Test that the left-indent of cells is preserved.
     */
    public function testAdaptedRowsWithIndent(): void
    {
        $maxTableWidth = 75;
        $buffer = new BufferedOutput();
        $table = new AdaptiveTable($buffer, $maxTableWidth);
        $table->setHeaders([
            ['Row', 'Lorem', 'ipsum', 'dolor', 'Indented'],
        ]);
        $table->setRows([
            ['#1', 'amet', 'consectetur', 'adipiscing elit', '  Quisque pulvinar'],
            ['#2', 'tellus sit amet', 'sollicitudin', 'tincidunt', '  risus'],
            new TableSeparator(),
            ['#3', 'risus', 'sem', 'mattis', '  ex'],
            ['#4', 'quis', 'luctus metus', 'lorem cursus', '  ligula'],
        ]);
        $table->render();
        $result = $buffer->fetch();

        // Test that the table fits into the maximum width.
        $lineWidths = [];
        foreach (explode(PHP_EOL, $result) as $line) {
            $lineWidths[] = strlen($line);
        }
        $this->assertLessThanOrEqual($maxTableWidth, max($lineWidths));

        $expected = <<<'EOT'
+-----+------------+-------------+--------------+------------+
| Row | Lorem      | ipsum       | dolor        | Indented   |
+-----+------------+-------------+--------------+------------+
| #1  | amet       | consectetur | adipiscing   |   Quisque  |
|     |            |             | elit         |   pulvinar |
| #2  | tellus sit | sollicitudi | tincidunt    |   risus    |
|     | amet       | n           |              |            |
+-----+------------+-------------+--------------+------------+
| #3  | risus      | sem         | mattis       |   ex       |
| #4  | quis       | luctus      | lorem cursus |   ligula   |
|     |            | metus       |              |            |
+-----+------------+-------------+--------------+------------+

EOT;
        $this->assertEquals($expected, $result);
    }

    /**
     * Test a non-wrapping table cell.
     */
    public function testAdaptedRowsWithNonWrappingCell(): void
    {
        $maxTableWidth = 60;
        $buffer = new BufferedOutput();
        $table = new AdaptiveTable($buffer, $maxTableWidth);
        $table->setHeaders([
            ['Row', 'Lorem', 'ipsum', 'dolor', 'sit'],
        ]);
        $table->setRows([
            ['#1', 'amet', 'consectetur', 'adipiscing elit', 'Quisque pulvinar'],
            ['#2', 'tellus sit amet', new AdaptiveTableCell('sollicitudin', ['wrap' => false]), 'tincidunt', 'risus'],
            ['#3', 'risus', 'sem', 'mattis', 'ex'],
            ['#4', 'quis', 'luctus metus', 'lorem cursus', 'ligula'],
        ]);
        $table->render();
        $result = $buffer->fetch();

        $expected = <<<'EOT'
+-----+------------+--------------+------------+----------+
| Row | Lorem      | ipsum        | dolor      | sit      |
+-----+------------+--------------+------------+----------+
| #1  | amet       | consectetur  | adipiscing | Quisque  |
|     |            |              | elit       | pulvinar |
| #2  | tellus sit | sollicitudin | tincidunt  | risus    |
|     | amet       |              |            |          |
| #3  | risus      | sem          | mattis     | ex       |
| #4  | quis       | luctus metus | lorem      | ligula   |
|     |            |              | cursus     |          |
+-----+------------+--------------+------------+----------+

EOT;
        $this->assertEquals($expected, $result);
    }

    /**
     * Tests that a string can be wrapped with decoration at various lengths.
     *
     * @param string $input
     * @param int[]  $maxLengths
     */
    private function assertWrappedWithDecoration(string $input, array $maxLengths = [5, 8, 13, 21, 34, 55, 89]): void
    {
        $o = new BufferedOutput();
        $f = $o->getFormatter();
        $table = new AdaptiveTable($o);
        $plainText = Helper::removeDecoration($f, $input);
        foreach ($maxLengths as $maxLength) {
            $plainWrapped = wordwrap($plainText, $maxLength, "\n", true);
            $tableWrapped = $table->wrapWithDecoration($input, $maxLength);
            $this->assertEquals($plainWrapped, Helper::removeDecoration($f, $tableWrapped));
        }
    }

    public function testWrapWithDecorationPlain(): void
    {
        $this->assertWrappedWithDecoration(
            'This is a test of raw text which should be wrapped as normal.'
        );
    }

    public function testWrapWithDecorationSimple(): void
    {
        $this->assertWrappedWithDecoration(
            'The quick brown <error>fox</error> <options=underscore>jumps</> over the lazy <info>dog</info>.'
        );
    }

    public function testWrapWithDecorationComplex(): void
    {
        $this->assertWrappedWithDecoration(
            'Lorem ipsum <info>dolor</info> sit <info>amet,</info> consectetur <error>adipiscing elit,</error> sed do eiusmod tempor <options=reverse>incididunt ut labore et dolore magna aliqua.</> Ut enim ad minim veniam, quis nostrud <options=underscore>exercitation</> ullamco laboris nisi ut aliquip ex ea commodo <options=reverse>consequat. Duis</> aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur. Excepteur sint occaecat (cupidatat) non <info>proident</info>, sunt in culpa qui officia deserunt mollit anim id est laborum.'
        );
    }

    public function testWrapWithDecorationIncludingEscapedTags(): void
    {
        $this->assertWrappedWithDecoration(
            'The quick brown fox <options=underscore>jumps</> over the lazy \\<script type="text/javascript">dog\\</script>.'
        );
    }
}
