<?php

namespace Platformsh\Cli\Tests\Command\Helper;

use Platformsh\Cli\Command\Variable\VariableDecodeCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class VariableDecodeTest extends \PHPUnit_Framework_TestCase
{
    private function runCommand(array $args) {
        $output = new BufferedOutput();
        (new VariableDecodeCommand())
            ->run(new ArrayInput($args), $output);

        return $output->fetch();
    }

    public function testDecode() {
        $var = base64_encode(json_encode([
            'foo' => 'bar',
            'fee' => 'bor',
            'nest' => ['nested' => 'baz'],
        ]));
        $this->assertEquals(
            'bar',
            rtrim($this->runCommand([
                'value' => $var,
                '--path' => 'foo',
            ]), "\n")
        );
        $this->assertEquals(
            'baz',
            rtrim($this->runCommand([
                'value' => $var,
                '--path' => 'nest.nested',
            ]), "\n")
        );
    }
}
