<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Util;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Util\Snippeter;

class SnippeterTest extends TestCase
{
    private string $begin;
    private string $end;
    private string $snippet;
    private string $dataDir;

    public function setUp(): void
    {
        $this->begin = '# BEGIN Test snippet' . PHP_EOL;
        $this->end = PHP_EOL . '# END';
        $this->snippet = 'Example snippet contents';
        $this->dataDir = dirname(__DIR__) . '/data/snippeter';
    }

    public function testUpdate(): void
    {
        $contents = (string) file_get_contents($this->dataDir . '/with-existing');
        $result = (new Snippeter())->updateSnippet($contents, $this->snippet, $this->begin, $this->end);
        $expected = file_get_contents($this->dataDir . '/after-update-existing');
        $this->assertEquals($expected, $result);
    }

    public function testInsert(): void
    {
        $contents = (string) file_get_contents($this->dataDir . '/without');
        $result = (new Snippeter())->updateSnippet($contents, $this->snippet, $this->begin, $this->end);
        $expected = file_get_contents($this->dataDir . '/after-insert');
        $this->assertEquals($expected, $result);
    }
}
