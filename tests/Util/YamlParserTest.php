<?php

namespace Platformsh\Cli\Tests\Util;

use Platformsh\Cli\Exception\InvalidConfigException;
use Platformsh\Cli\Util\YamlParser;

class YamlParserTest extends \PHPUnit_Framework_TestCase
{
    public function testParseValidYaml()
    {
        $file = 'tests/data/apps/complex-yaml/.platform.app.yaml';
        $parsed = (new YamlParser())->parseFile($file);
        $expected = [
            'name' => 'complex-yaml',
            'web' => [
                'locations' => [
                    '/' => ['allow' => true, 'scripts' => true],
                    '/subpath' => ['allow' => true, 'scripts' => true],
                ],
            ],
            'hooks' => [
                'build' => file_get_contents('tests/data/apps/complex-yaml/build-hook.sh'),
            ],
            'anchor' => ['foo' => 'bar'],
            'reuse' => ['foo' => 'bar'],
            'anchor-include' => ['allow' => true, 'scripts' => true],
            'reuse-include' => ['allow' => true, 'scripts' => true],
        ];
        $this->assertEquals($expected, $parsed);
    }

    public function testParseInvalidYaml()
    {
        $file = 'tests/data/apps/complex-yaml/.platform.app.yaml';
        $content = file_get_contents($file);
        $content .= "\ntest: !include nonexistent.yml";
        $this->setExpectedException(InvalidConfigException::class, 'File not found');
        (new YamlParser())->parseContent($content, $file);
    }

    public function testParseIndentedYaml()
    {
        $file = 'example.yaml';
        $content = <<<EOF

  name: example-indented-yaml
  key: value

  foo:
    nested: bar
EOF;
;
        $result = (new YamlParser())->parseContent($content, $file);
        $this->assertEquals([
            'name' => 'example-indented-yaml',
            'key' => 'value',
            'foo' => ['nested' => 'bar'],
        ], $result);
    }
}
