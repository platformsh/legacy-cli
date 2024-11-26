<?php

namespace Platformsh\Cli\Event;

use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Contracts\EventDispatcher\Event;

class LoginRequiredEvent extends Event
{
    private $authMethods;
    private $maxAge;
    private $hasApiToken;

    /**
     * @param string[] $authMethods
     * @param int|null $maxAge
     * @param bool $hasApiToken
     */
    public function __construct(array $authMethods = [], $maxAge = null, $hasApiToken = false)
    {
        $this->authMethods = $authMethods;
        $this->maxAge = $maxAge;
        $this->hasApiToken = $hasApiToken;
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

    public function getExtendedMessage(Config $config)
    {
        $message = $this->getMessage();
        if ($this->hasApiToken) {
            if ($this->authMethods === ['mfa']) {
                $message .= "\n\nThe API token may need to be re-created after enabling MFA.";
            }
            return $message;
        }
        $executable = $config->get('application.executable');
        $cmd = 'login';
        if ($options = $this->getLoginOptionsCmdLine()) {
            $cmd .= ' ' . $options;
        }
        $message .= "\n\nPlease log in by running:\n    $executable $cmd";
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

    /**
     * @return bool
     */
    public function hasApiToken()
    {
        return $this->hasApiToken;
    }
}
