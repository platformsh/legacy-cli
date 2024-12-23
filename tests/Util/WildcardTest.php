<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Util;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Util\Wildcard;

class WildcardTest extends TestCase
{
    public function testSelect(): void
    {
        $cases = [
            [['a', 'b', 'c'], ['a'], ['a']],
            [['foo', 'bar', 'baz'], ['foo'], ['foo']],
            [['foo', 'bar', 'baz', 'bazz', 'boz'], ['ba%'], ['bar', 'baz', 'bazz']],
            [
                ['feature/apple', 'feature/pear', 'feature/banana', 'feature/blueberry'],
                ['feature/banana'],
                ['feature/banana'],
            ],
            [
                ['feature/apple', 'feature/pear', 'feature/banana', 'feature/blueberry', 'feature/blackberry', 'release/blueberry'],
                ['f%b%y', 'f%/pear', 'hotfix/%'],
                ['feature/blueberry', 'feature/blackberry', 'feature/pear'],
            ],
        ];
        foreach ($cases as $i => $case) {
            [$subjects, $wildcards, $result] = $case;
            $this->assertEquals($result, Wildcard::select($subjects, $wildcards), "Case $i");
        }
    }
}
