<?php

namespace Platformsh\Cli\Util;

class Jwt
{
    private $token;

    /**
     * @param string $token
     */
    public function __construct($token)
    {
        $this->token = $token;
    }

    /**
     * Returns the JWT payload claims without verification.
     *
     * @return false|array<string, mixed>
     */
    public function unsafeGetUnverifiedClaims()
    {
        $split = \explode('.', $this->token, 3);
        if (!isset($split[1])) {
            return false;
        }
        $json = \base64_decode($split[1], true);
        if (!$json) {
            return false;
        }
        return \json_decode($json, true) ?: false;
    }
}
