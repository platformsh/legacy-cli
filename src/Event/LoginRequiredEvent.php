<?php

namespace Platformsh\Cli\Event;

use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\EventDispatcher\Event;

class LoginRequiredEvent extends Event
{
    private $authMethods;
    private $maxAge;

    /**
     * @param string[] $authMethods
     * @param int|null $maxAge
     */
    public function __construct(array $authMethods = [], $maxAge = 0)
    {
        $this->authMethods = $authMethods;
        $this->maxAge = $maxAge;
    }

    public function getMessage()
    {
        $message = 'Authentication is required.';
        if (count($this->authMethods) > 0 || $this->maxAge !== null) {
            $message = 'Re-authentication is required.';
        }
        if (count($this->authMethods) === 1) {
            if ($this->authMethods[0] === 'mfa') {
                $message = 'Multi-factor authentication (MFA) is required.';
            } elseif (strpos($this->authMethods[0], 'sso:') === 0) {
                $message = 'Single sign-on (SSO) is required.';
            }
        } elseif ($this->maxAge !== null) {
            $message = 'More recent authentication is required.';
        }
        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function getLoginOptions() {
        $loginOptions = [];
        if (count($this->authMethods) > 0) {
            $loginOptions['--method'] = $this->authMethods;
        }
        if ($this->maxAge !== null) {
            $loginOptions['--max-age'] = $this->maxAge;
        }
        return $loginOptions;
    }

    /**
     * @return string
     */
    public function getLoginOptionsCmdLine() {
        $args = [];
        foreach ($this->getLoginOptions() as $option => $value) {
            $args[] = $option;
            $args[] = OsUtil::escapeShellArg(is_array($value) ? implode(',', $value) : $value);
        }
        return implode(' ', $args);
    }
}
