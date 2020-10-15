<?php

namespace Platformsh\Cli\Tests\Util;

use Platformsh\Cli\Util\Snippeter;

class SnippeterTest extends \PHPUnit_Framework_TestCase
{
    private $begin;
    private $end;
    private $snippet;
    private $dataDir;

    public function setUp()
    {
        $this->begin = '# BEGIN Test snippet' . PHP_EOL;
        $this->end = PHP_EOL . '# END';
        $this->snippet = 'Example snippet contents';
        $this->dataDir = dirname(__DIR__) . '/data/snippeter';
    }

    public function testUpdate()
    {
        $contents = file_get_contents($this->dataDir . '/with-existing');
        $result = (new Snippeter())->updateSnippet($contents, $this->snippet, $this->begin, $this->end);
        $expected = file_get_contents($this->dataDir . '/after-update-existing');
        $this->assertEquals($expected, $result);
    }

    public function testInsert()
    {
        $contents = file_get_contents($this->dataDir . '/without');
        $result = (new Snippeter())->updateSnippet($contents, $this->snippet, $this->begin, $this->end);
        $expected = file_get_contents($this->dataDir . '/after-insert');
        $this->assertEquals($expected, $result);
    }
}
