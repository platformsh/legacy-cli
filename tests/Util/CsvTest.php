<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Util;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Util\Csv;
use Platformsh\Cli\Util\PlainFormat;

class CsvTest extends TestCase
{
    // Data from a Wikipedia example.
    // https://en.wikipedia.org/wiki/Comma-separated_values
    private const DATA = [
        ['Year', 'Make', 'Model', 'Description', 'Price'],
        ['1997', 'Ford', 'E350', 'ac, abs, moon', '3000.00'],
        ['1999', 'Chevy', 'Venture "Extended Edition"', '', '4900.00'],
        ['1999', 'Chevy', 'Venture "Extended Edition, Very Large"', '', '5000.00'],
        ['1996', 'Jeep', 'Grand Cherokee', "MUST SELL!\nair, moon roof, loaded", '4799.00'],
    ];

    public function testRfc4180(): void
    {
        $expected = "Year,Make,Model,Description,Price\r\n"
            . "1997,Ford,E350,\"ac, abs, moon\",3000.00\r\n"
            . "1999,Chevy,\"Venture \"\"Extended Edition\"\"\",,4900.00\r\n"
            . "1999,Chevy,\"Venture \"\"Extended Edition, Very Large\"\"\",,5000.00\r\n"
            . "1996,Jeep,Grand Cherokee,\"MUST SELL!\r\nair, moon roof, loaded\",4799.00\r\n";
        $actual = (new Csv())->format(self::DATA);
        $this->assertEquals($expected, $actual);
    }

    public function testTsv(): void
    {
        $expected = "Year\tMake\tModel\tDescription\tPrice\n"
            . "1997\tFord\tE350\tac, abs, moon\t3000.00\n"
            . "1999\tChevy\t\"Venture \"\"Extended Edition\"\"\"\t\t4900.00\n"
            . "1999\tChevy\t\"Venture \"\"Extended Edition, Very Large\"\"\"\t\t5000.00\n"
            . "1996\tJeep\tGrand Cherokee\t\"MUST SELL!\nair, moon roof, loaded\"\t4799.00";
        $actual = (new Csv("\t", "\n"))->format(self::DATA, false);
        $this->assertEquals($expected, $actual);
    }

    public function testPlain(): void
    {
        $expected = "Year\tMake\tModel\tDescription\tPrice\n"
            . "1997\tFord\tE350\tac, abs, moon\t3000.00\n"
            . "1999\tChevy\tVenture \"Extended Edition\"\t\t4900.00\n"
            . "1999\tChevy\tVenture \"Extended Edition, Very Large\"\t\t5000.00\n"
            . "1996\tJeep\tGrand Cherokee\tMUST SELL! air, moon roof, loaded\t4799.00";
        $actual = (new PlainFormat())->format(self::DATA, false);
        $this->assertEquals($expected, $actual);
    }
}
