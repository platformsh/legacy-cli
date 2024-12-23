<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\CredentialHelper;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\CredentialHelper\Manager;
use Platformsh\Cli\CredentialHelper\SessionStorage;
use Platformsh\Cli\Service\Config;
use Platformsh\Client\Session\Session;

class CredentialHelperTest extends TestCase
{
    private Manager $manager;
    private SessionStorage $storage;

    public function setUp(): void
    {
        $this->manager = new Manager(new Config());
        $this->storage = new SessionStorage($this->manager, 'CLI Test');
    }

    public function tearDown(): void
    {
        $this->storage->deleteAll();
    }

    public function testCredentialStorage(): void
    {
        if (!$this->manager->isSupported()) {
            $this->markTestIncomplete('Skipping credential helper test (not supported on this system)');
        }
        $this->manager->install();

        // Set up the session.
        $testData = ['foo' => 'bar'];
        $session = new Session();
        $session->setStorage($this->storage);

        // Save data.
        foreach ($testData as $k => $v) {
            $session->set($k, $v);
        }
        $session->save();

        // Reset the session, reload from the credential helper, and check session data.
        $session = new Session();
        $session->setStorage($this->storage);
        foreach ($testData as $k => $v) {
            $this->assertEquals($v, $session->get($k));
        }

        // Clear and reset the session, and check the session is empty.
        $session->clear();
        $session->save();
        $session = new Session();
        $session->setStorage($this->storage);
        foreach ($testData as $k => $v) {
            $this->assertEquals(null, $session->get($k));
        }

        // Write to the session again, and check deleteAllSessions() works.
        $session->set('some key', 'some value');
        $session->save();
        $session = new Session();
        $session->setStorage($this->storage);
        $this->assertEquals('some value', $session->get('some key'));
        $this->storage->deleteAll();
        $session = new Session();
        $session->setStorage($this->storage);
        $this->assertEquals(null, $session->get('some key'));
    }
}
