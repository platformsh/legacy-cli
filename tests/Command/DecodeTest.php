<?php

namespace Platformsh\Cli\Tests\Command;

use Platformsh\Cli\Command\DecodeCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @group commands
 */
class DecodeTest extends \PHPUnit_Framework_TestCase
{
    private function runCommand(array $args) {
        $output = new BufferedOutput();
        (new DecodeCommand())
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
                '--property' => 'foo',
            ]), "\n")
        );
        $this->assertEquals(
            'baz',
            rtrim($this->runCommand([
                'value' => $var,
                '--property' => 'nest.nested',
            ]), "\n")
        );
    }
}
