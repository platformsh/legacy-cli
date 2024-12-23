<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Command\App;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Tests\MockApp;
use Symfony\Component\Yaml\Parser;

#[Group('commands')]
class AppConfigGetTest extends TestCase
{
    public function testGetConfig(): void
    {
        $app = base64_encode((string) json_encode([
            'type' => 'php:7.3',
            'name' => 'app',
            'disk' => 512,
            'mounts' => [],
            'blank' => null,
        ]));
        putenv('PLATFORM_APPLICATION=' . $app);
        $this->assertEquals(
            'app',
            (new Parser())->parse(MockApp::runAndReturnOutput('app:config', [
                '--property' => 'name',
            ])),
        );
        $this->assertEquals(
            [],
            (new Parser())->parse(MockApp::runAndReturnOutput('app:config', [
                '--property' => 'mounts',
            ])),
        );
        $this->assertEquals(
            '',
            (new Parser())->parse(MockApp::runAndReturnOutput('app:config', [
                '--property' => 'blank',
            ])),
        );
        putenv('PLATFORM_APPLICATION=');
    }
}
