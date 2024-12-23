<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Util;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Util\SslUtil;

class SslUtilTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = dirname(__DIR__) . '/data/ssl';
    }

    public function testValidate(): void
    {
        $result = (new SslUtil())->validate($this->dir . '/cert.pem', $this->dir . '/key.pem', [$this->dir . '/chain.crt']);
        $this->assertArrayHasKey('certificate', $result);
        $this->assertArrayHasKey('key', $result);
        $this->assertCount(3, $result['chain']);
    }

    public function testValidateWrongFilename(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The private key file could not be read');
        (new SslUtil())->validate($this->dir . '/cert.pem', $this->dir . '/nonexistent-key.pem', []);
    }

    public function testValidateWrongKey(): void
    {
        if (!\extension_loaded('openssl')) {
            $this->markTestIncomplete('openssl extension not loaded');
        } else {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('The provided certificate does not match the provided private key');
            (new SslUtil())->validate($this->dir . '/cert.pem', $this->dir . '/wrongkey.pem', []);
        }
    }

    public function testValidateInvalidKey(): void
    {
        if (!\extension_loaded('openssl')) {
            $this->markTestIncomplete('openssl extension not loaded');
        } else {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Private key not valid');
            (new SslUtil())->validate($this->dir . '/cert.pem', $this->dir . '/invalid-key.pem', []);
        }
    }

    public function testValidateInvalidCert(): void
    {
        if (!\extension_loaded('openssl')) {
            $this->markTestIncomplete('openssl extension not loaded');
        } else {
            $filename = $this->dir . '/invalid-cert.pem';
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('The certificate file is not a valid X509 certificate: ' . $filename);
            (new SslUtil())->validate($filename, $this->dir . '/key.pem', []);
        }
    }
}
