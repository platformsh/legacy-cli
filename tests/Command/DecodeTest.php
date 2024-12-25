<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Command;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Tests\MockApp;

#[Group('commands')]
class DecodeTest extends TestCase
{
    public function testDecode(): void
    {
        $var = base64_encode((string) json_encode([
            'foo' => 'bar',
            'fee' => 'bor',
            'nest' => ['nested' => 'baz'],
        ]));
        $this->assertEquals(
            'bar',
            rtrim(MockApp::runAndReturnOutput('decode', [
                'value' => $var,
                '--property' => 'foo',
            ]), "\n"),
        );
        $this->assertEquals(
            'baz',
            rtrim(MockApp::runAndReturnOutput('decode', [
                'value' => $var,
                '--property' => 'nest.nested',
            ]), "\n"),
        );
    }

    public function testDecodeEmptyObject(): void
    {
        $this->assertEquals(
            '{}',
            rtrim(MockApp::runAndReturnOutput('decode', [
                'value' => base64_encode((string) json_encode(new \stdClass())),
            ]), "\n"),
        );

        try {
            $this->assertEquals(
                'Property not found: nonexistent',
                rtrim(MockApp::runAndReturnOutput('decode', [
                    'value' => base64_encode((string) json_encode(new \stdClass())),
                    '--property' => 'nonexistent',
                ]), "\n"),
            );
        } catch (\RuntimeException) {
        }
    }
}
