<?php

namespace Platformsh\Cli\Tests\Command\App;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Command\App\AppConfigGetCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Yaml\Parser;

class AppConfigGetTest extends TestCase
{
    private function runCommand(array $args) {
        $output = new BufferedOutput();
        (new AppConfigGetCommand())
            ->run(new ArrayInput($args), $output);

        return $output->fetch();
    }

    public function testGetConfig() {
        $app = base64_encode(json_encode([
            'type' => 'php:7.3',
            'name' => 'app',
            'disk' => 512,
            'mounts' => [],
            'blank' => null,
        ]));
        putenv('PLATFORM_APPLICATION=' . $app);
        $this->assertEquals(
            'app',
            (new Parser)->parse($this->runCommand([
                '--property' => 'name',
            ]))
        );
        $this->assertEquals(
            [],
            (new Parser)->parse($this->runCommand([
                '--property' => 'mounts',
            ]))
        );
        $this->assertEquals(
            '',
            (new Parser)->parse($this->runCommand([
                '--property' => 'blank',
            ]))
        );
        putenv('PLATFORM_APPLICATION=');
    }
}
