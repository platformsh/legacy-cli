<?php

namespace Platformsh\Cli\Tests\CredentialHelper;

use Platformsh\Cli\CredentialHelper\Manager;
use Platformsh\Cli\CredentialHelper\SessionStorage;
use Platformsh\Cli\Service\Config;
use Platformsh\Client\Session\Session;

class CredentialHelperTest extends \PHPUnit_Framework_TestCase
{
    private $manager;
    private $storage;

    public function setUp()
    {
        $this->manager = new Manager(new Config());
        $this->storage = new SessionStorage($this->manager, 'CLI Test');
    }

    public function tearDown()
    {
        $this->storage->deleteAll();
    }

    public function testCredentialStorage()
    {
        if (!$this->manager->isSupported()) {
            $this->markTestIncomplete('Skipping credential helper test (not supported on this system)');
            return;
        }
        $this->manager->install();

        // Set up the session.
        $testData = ['foo' => 'bar', '1' => ['2' => '3']];
        $session = new Session();
        $session->setStorage($this->storage);

        // Save data.
        $session->setData($testData);
        $session->save();

        // Reset the session, reload from the credential helper, and check session data.
        $session->load(true);
        $this->assertEquals($testData, $session->getData());

        // Clear and reset the session, and check the session is empty.
        $session->clear();
        $session->save();
        $session->load(true);
        $this->assertEquals([], $session->getData());

        // Write to the session again, and check deleteAllSessions() works.
        $session->setData($testData);
        $session->save();
        $session->load(true);
        $this->assertNotEmpty($session->getData());
        $this->storage->deleteAll();
        $session->load(true);
        $this->assertEmpty($session->getData());
    }
}
