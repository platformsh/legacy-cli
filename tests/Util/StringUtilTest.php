<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Util;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Util\StringUtil;

class StringUtilTest extends TestCase
{
    public function testBetween(): void
    {
        $cases = [
            ['_BEGIN_foo_END_', '_BEGIN_', '_END_', 'foo'],
            ['_BEGIN_foo bar', '_BEGIN_', '_END_', null],
            ['_BEGIN_foo bar_END_', '_BEGIN_', '_END_', 'foo bar'],
            ["_BEGIN_\nfoo\n_END_", '_BEGIN_', '_END_', "\nfoo\n"],
            ["_BEGIN_\nfoo\n_END_", "_BEGIN_\n", '_END_', "foo\n"],
            ["_BEGIN_\nfoo\n_END_", "_BEGIN_\n", "\n_END_", 'foo'],
        ];
        foreach ($cases as $key => $case) {
            [$str, $begin, $end, $result] = $case;
            $this->assertEquals($result, StringUtil::between($str, $begin, $end), "case $key");
        }
    }
}
