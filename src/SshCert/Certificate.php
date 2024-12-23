<?php

declare(strict_types=1);

namespace Platformsh\Cli\SshCert;

use Platformsh\Client\SshCert\Metadata;

class Certificate
{
    private readonly Metadata $metadata;
    private readonly string|bool $contents;

    /** @var array<string, mixed>|null */
    private ?array $tokenClaims = null;
    /** @var array<string, mixed>|null */
    private ?array $inlineAccess = null;

    public function __construct(private readonly string $certFile, private readonly string $privateKeyFile)
    {
        $this->contents = \file_get_contents($this->certFile);
        if (!$this->contents) {
            throw new \RuntimeException('Failed to read certificate file: ' . $this->certFile);
        }
        $this->metadata = new Metadata($this->contents);
    }

    /**
     * Returns if two certificates are identical.
     *
     * @param Certificate $cert
     *
     * @return bool
     */
    public function isIdentical(Certificate $cert): bool
    {
        return $cert->contents === $this->contents;
    }

    /**
     * @return string
     */
    public function certificateFilename(): string
    {
        return $this->certFile;
    }

    /**
     * @return string
     */
    public function privateKeyFilename(): string
    {
        return $this->privateKeyFile;
    }

    /**
     * Returns certificate metadata.
     *
     * @return Metadata
     */
    public function metadata(): Metadata
    {
        return $this->metadata;
    }

    /**
     * Checks if the certificate has expired.
     *
     * @param int $buffer
     *   A duration in seconds by which to reduce the certificate's lifetime,
     *   to account for clock drift. Defaults to 120 (two minutes).
     *
     * @return bool
     */
    public function hasExpired(int $buffer = 120): bool
    {
        return $this->metadata->getValidBefore() - $buffer < \time();
    }

    /**
     * Checks the certificate's "has MFA" claim: whether the user was authenticated via MFA.
     *
     * @return bool
     */
    public function hasMfa(): bool
    {
        if (\array_key_exists('has-mfa@platform.sh', $this->metadata->getExtensions())) {
            return true;
        }
        $claims = $this->tokenClaims();
        return isset($claims['amr']) && in_array('mfa', $claims['amr'], true);
    }

    /**
     * Checks the certificate's "is app" claim: whether the authentication mode is non-interactive.
     *
     * @return bool
     */
    public function isApp(): bool
    {
        return \array_key_exists('is-app@platform.sh', $this->metadata->getExtensions());
    }

    /**
     * Returns token claims that were embedded in the certificate.
     *
     * @return array{
     *     auth_time?: int,
     *     amr?: string[],
     *     grant?: string,
     *     scp?: string[],
     *     act?: array{sub?: string, src?: string}
     * }
     */
    public function tokenClaims(): array
    {
        if (!isset($this->tokenClaims)) {
            $ext = $this->metadata->getExtensions();
            $this->tokenClaims = isset($ext['token-claims@platform.sh'])
                ? json_decode((string) $ext['token-claims@platform.sh'], true)
                : [];
        }
        return $this->tokenClaims;
    }

    /**
     * Returns a list of SSO providers that were used for authentication.
     *
     * @return string[]
     */
    public function ssoProviders(): array
    {
        $tokenClaims = $this->tokenClaims();
        if (!isset($tokenClaims['amr'])) {
            return [];
        }
        $ssoProviders = [];
        foreach ($tokenClaims['amr'] as $authMethod) {
            if (str_starts_with($authMethod, 'sso:')) {
                $ssoProviders[] = substr($authMethod, 4);
            }
        }
        return $ssoProviders;
    }

    /**
     * Returns access info embedded in the certificate.
     *
     * @return array<string, mixed>
     */
    public function inlineAccess(): array
    {
        if (!isset($this->inlineAccess)) {
            $ext = $this->metadata->getExtensions();
            $this->inlineAccess = isset($ext['access@platform.sh'])
                ? json_decode((string) $ext['access@platform.sh'], true)
                : [];
        }
        return $this->inlineAccess;
    }
}
