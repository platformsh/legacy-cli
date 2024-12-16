<?php

namespace Platformsh\Cli\Tests\Command;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Command\DecodeCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @group commands
 */
class DecodeTest extends TestCase
{
    private function runCommand(array $args) {
        $output = new BufferedOutput();
        $input = new ArrayInput($args);
        $input->setInteractive(false);
        (new DecodeCommand())->run($input, $output);

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

    public function testDecodeEmptyObject() {
        $this->assertEquals(
            '{}',
            rtrim($this->runCommand([
                'value' => base64_encode(json_encode(new \stdClass()))
            ]), "\n")
        );

        $this->assertEquals(
            'Property not found: nonexistent',
            rtrim($this->runCommand([
                'value' => base64_encode(json_encode(new \stdClass())),
                '--property' => 'nonexistent'
            ]), "\n")
        );
    }
}
