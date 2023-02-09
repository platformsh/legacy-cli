<?php

namespace Platformsh\Cli\Model;

use GuzzleHttp\ClientInterface;
use Platformsh\Client\Model\Domain;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Resource;

/**
 * A domain name on a Platform.sh environment.
 *
 * @property-read string $id
 * @property-read string $name
 * @property-read string $replacement_for
 * @property-read string $created_at
 * @property-read string $updated_at
 */
class EnvironmentDomain extends Resource
{
    public static function getList(Environment $environment, ClientInterface $client)
    {
        return static::getCollection($environment->getLink('#domains'), 0, [], $client);
    }

    /**
     * @param ClientInterface $client
     * @param Environment $environment
     * @param string $name
     * @param string $replacementFor
     * @param array $ssl
     * @return \Platformsh\Client\Model\Result
     */
    public static function add(ClientInterface $client, Environment $environment, $name, $replacementFor = '', $ssl = [])
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
