<?php

namespace Platformsh\Cli\Tests;

use Platformsh\Cli\Session\KeychainStorage;
use Platformsh\Client\Session\Session;

class KeychainStorageTest extends \PHPUnit_Framework_TestCase
{
    public function testKeyChainStorage()
    {
        if (!KeychainStorage::isSupported()) {
            $this->markTestIncomplete('Skipping keychain test (not supported on this system)');
            return;
        }

        // Set up the session.
        $testData = ['foo' => 'bar', '1' => ['2' => '3']];
        $keychain = new KeychainStorage('platformsh-cli-test');
        $session = new Session();
        $session->setStorage($keychain);

        // Save data.
        $session->setData($testData);
        $session->save();

        // Reset the session, reload from the keychain, and check session data.
        $session->load(true);
        $this->assertEquals($testData, $session->getData());

        // Delete data from the keychain, reset the session, and check the
        // session is empty.
        $keychain->deleteAll();
        $session->load(true);
        $this->assertEquals([], $session->getData());
    }
}
