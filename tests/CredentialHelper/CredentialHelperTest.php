<?php

namespace Platformsh\Cli\Tests\CredentialHelper;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\CredentialHelper\Manager;
use Platformsh\Cli\CredentialHelper\SessionStorage;
use Platformsh\Cli\Service\Config;
use Platformsh\Client\Session\Session;

class CredentialHelperTest extends TestCase
{
    private $manager;
    private $storage;

    public function setUp(): void
    {
        $this->manager = new Manager(new Config());
        $this->storage = new SessionStorage($this->manager, 'CLI Test');
    }

    public function tearDown(): void
    {
        $this->storage->deleteAll();
    }

    public function testCredentialStorage()
    {
        if (!$this->manager->isSupported()) {
            $this->markTestIncomplete('Skipping credential helper test (not supported on this system)');
        }
        $this->manager->install();

        // Set up the session.
        $session = new Session('default', [], $this->storage);

        // Save data.
        $session->set('foo', 'bar');
        $session->save();

        // Reset the session, reload from the credential helper, and check session data.
        $session = new Session('default', [], $this->storage);
        $this->assertEquals('bar', $session->get('foo'));

        // Clear and reset the session, and check the session is empty.
        $session->clear();
        $session->save();
        $session = new Session('default', [], $this->storage);
        $this->assertEquals(null, $session->get('foo'));

        // Write to the session again, and check deleteAllSessions() works.
        $session = new Session('default', [], $this->storage);
        $session->set('foo', 'baz');
        $session->save();
        $session = new Session('default', [], $this->storage);
        $this->assertEquals('baz', $session->get('foo'));
        $this->storage->deleteAll();
        $session = new Session('default', [], $this->storage);
        $this->assertEmpty($session->get('foo'));
    }
}
