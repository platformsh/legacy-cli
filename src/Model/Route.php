<?php

declare(strict_types=1);

namespace Platformsh\Cli\Model;

use Platformsh\Client\DataStructure\ReadOnlyStructureTrait;

/**
 * @property-read string      $original_url
 * @property-read string      $type
 * @property-read string      $upstream
 * @property-read string      $to
 * @property-read bool        $primary
 * @property-read string|null $id
 * @property-read string      $url
 */
final class Route
{
    use ReadOnlyStructureTrait;

    /**
     * @param array<string, mixed> $data
     */
    public static function fromData(array $data): self
    {
        return new self($data + ['id' => null, 'primary' => false]);
    }

    /**
     * Gets the app or service name that is the upstream for a route.
     *
     * @return string|false
     */
    public function getUpstreamName(): false|string
    {
        if (!isset($this->data['upstream'])) {
            return false;
        }

        return explode(':', (string) $this->data['upstream'], 2)[0];
    }

    /**
     * Translates routes found in $environment->getRoutes() to Route objects.
     *
     * @param \Platformsh\Client\Model\Deployment\Route[] $routes
     *
     * @return Route[]
     * @see \Platformsh\Client\Model\Environment::getRoutes()
     */
    public static function fromDeploymentApi(array $routes): array
    {
        $result = [];
        foreach ($routes as $url => $route) {
            $properties = $route->getProperties();
            $properties['url'] = $url;
            $result[] = self::fromData($properties);
        }

        return self::sort($result);
    }

    /**
     * Translates routes found in PLATFORM_ROUTES to Route objects.
     *
     * @param array<string, mixed> $routes
     *
     * @return Route[]
     */
    public static function fromVariables(array $routes): array
    {
        $result = [];

        foreach ($routes as $url => $route) {
            $route['url'] = $url;
            $result[] = self::fromData($route);
        }

        return self::sort($result);
    }

    /**
     * Sorts routes, preferring based on ID or primary status, and preferring shorter URLs with HTTPS.
     *
     * @param Route[] $routes
     *
     * @return Route[]
     */
    private static function sort(array $routes): array
    {
        usort($routes, function (Route $a, Route $b): int {
            $result = 0;
            if ($a->primary) {
                $result -= 4;
            } elseif ($b->primary) {
                $result += 4;
            }
            foreach ([$a, $b] as $key => $route) {
                if (parse_url($route->url, PHP_URL_SCHEME) === 'https') {
                    $result += $key === 0 ? -2 : 2;
                }
            }
            $result += strlen($a->url) <= strlen($b->url) ? -1 : 1;
            return $result;
        });

        return $routes;
    }
}
