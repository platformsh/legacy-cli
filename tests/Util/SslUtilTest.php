<?php

namespace Platformsh\Cli\Tests\Util;

use Platformsh\Cli\Util\SslUtil;

class SslUtilTest extends \PHPUnit_Framework_TestCase
{
    private $dir;

    protected function setUp()
    {
        $this->dir = dirname(__DIR__) . '/data/ssl';
        \PHPUnit_Framework_Error_Warning::$enabled = false;
    }

    public function testValidate()
    {
        $result = (new SslUtil())->validate($this->dir . '/cert.pem', $this->dir . '/key.pem', [$this->dir . '/chain.crt']);
        $this->assertArrayHasKey('certificate', $result);
        $this->assertArrayHasKey('key', $result);
        $this->assertCount(3, $result['chain']);
    }

    public function testValidateWrongFilename()
    {
        $this->setExpectedException(\InvalidArgumentException::class, 'The private key file could not be read');
        (new SslUtil())->validate($this->dir . '/cert.pem', $this->dir . '/nonexistent-key.pem', []);
    }

    public function testValidateWrongKey()
    {
        if (!\extension_loaded('openssl')) {
            $this->markTestIncomplete('openssl extension not loaded');
        } else {
            $this->setExpectedException(\InvalidArgumentException::class, 'The provided certificate does not match the provided private key');
            (new SslUtil())->validate($this->dir . '/cert.pem', $this->dir . '/wrongkey.pem', []);
        }
    }

    public function testValidateInvalidKey()
    {
        if (!\extension_loaded('openssl')) {
            $this->markTestIncomplete('openssl extension not loaded');
        } else {
            $this->setExpectedException(\InvalidArgumentException::class, 'Private key not valid');
            (new SslUtil())->validate($this->dir . '/cert.pem', $this->dir . '/invalid-key.pem', []);
        }
    }

    public function testValidateInvalidCert()
    {
        if (!\extension_loaded('openssl')) {
            $this->markTestIncomplete('openssl extension not loaded');
        } else {
            $filename = $this->dir . '/invalid-cert.pem';
            $this->setExpectedException(\InvalidArgumentException::class, 'The certificate file is not a valid X509 certificate: ' . $filename);
            (new SslUtil())->validate($filename, $this->dir . '/key.pem', []);
        }
    }
}
