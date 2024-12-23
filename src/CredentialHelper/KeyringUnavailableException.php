<?php

declare(strict_types=1);

namespace Platformsh\Cli\CredentialHelper;

use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * An exception thrown when the login keyring is unavailable.
 */
class KeyringUnavailableException extends \RuntimeException
{
    public static function fromTimeout(ProcessTimedOutException $_): KeyringUnavailableException
    {
        $type = OsUtil::isOsX() ? 'keychain' : 'keyring';
        $message = sprintf('The credential helper process timed out while trying to access the %s.', $type);
        $message .= "\n" . sprintf('This may be due to a system password prompt: is the login %s unlocked?', $type);
        return new KeyringUnavailableException($message);
    }

    public static function fromFailure(ProcessFailedException $e): KeyringUnavailableException
    {
        $type = OsUtil::isOsX() ? 'keychain' : 'keyring';
        $message = sprintf('The credential helper process failed while trying to access the %s.', $type);
        $process = $e->getProcess();
        if ($process->getExitCode() === 2 && str_contains($process->getErrorOutput(), 'libsecret-CRITICAL')) {
            $message .= "\n" . sprintf('This can happen when the password dialog is dismissed. Is the login %s unlocked?', $type);
        } else {
            $message .= "\n" . $e->getMessage();
        }
        return new KeyringUnavailableException($message);
    }
}
