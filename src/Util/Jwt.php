<?php

declare(strict_types=1);

namespace Platformsh\Cli\Util;

readonly class Jwt
{
    public function __construct(private string $token) {}

    /**
     * Returns the JWT payload claims without verification.
     *
     * @return false|array<string, mixed>
     */
    public function unsafeGetUnverifiedClaims(): array|false
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
