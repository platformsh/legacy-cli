<?php

declare(strict_types=1);

namespace Platformsh\Cli\Model;

use Platformsh\Client\Model\Result;
use GuzzleHttp\ClientInterface;
use Platformsh\Client\Model\ApiResourceBase;
use Platformsh\Client\Model\Domain;
use Platformsh\Client\Model\Environment;

/**
 * A domain name on a Platform.sh environment.
 *
 * @property-read string $id
 * @property-read string $name
 * @property-read string $replacement_for
 * @property-read string $created_at
 * @property-read string $updated_at
 * @property-read array<string, string> $ssl
 */
class EnvironmentDomain extends ApiResourceBase
{
    /** @return EnvironmentDomain[] */
    public static function getList(Environment $environment, ClientInterface $client): array
    {
        return static::getCollection($environment->getLink('#domains'), 0, [], $client);
    }

    /**
     * Adds a domain to an environment.
     *
     * @param array<string, mixed> $ssl
     */
    public static function add(ClientInterface $client, Environment $environment, string $name, string $replacementFor = '', array $ssl = []): Result
    {
        $body = ['name' => $name];
        if (!empty($replacementFor)) {
            $body['replacement_for'] = $replacementFor;
        }
        if (!empty($ssl)) {
            $body['ssl'] = $ssl;
        }

        return Domain::create($body, $environment->getLink('#domains'), $client);
    }
}
