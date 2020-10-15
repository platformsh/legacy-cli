<?php

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
class Route
{
    use ReadOnlyStructureTrait;

    public static function fromData(array $data) {
        return new static($data + ['id' => null, 'primary' => false]);
    }

    /**
     * Gets the app or service name that is the upstream for a route.
     *
     * @return string|false
     */
    public function getUpstreamName() {
        if (!isset($this->data['upstream'])) {
            return false;
        }

        return explode(':', $this->data['upstream'], 2)[0];
    }

    /**
     * Translates routes found in $environment->getRoutes() to Route objects.
     *
     * @see \Platformsh\Client\Model\Environment::getRoutes()
     *
     * @param \Platformsh\Client\Model\Deployment\Route[] $routes
     *
     * @return \Platformsh\Cli\Model\Route[]
     */
    public static function fromDeploymentApi(array $routes)
    {
        $result = [];
        foreach ($routes as $url => $route) {
            $properties = $route->getProperties();
            $properties['url'] = $url;
            $result[] = static::fromData($properties);
        }

        return static::sort($result);
    }

    /**
     * Translates routes found in $environment->getRoutes() to Route objects.
     *
     * @see \Platformsh\Client\Model\Environment::getRoutes()
     *
     * @param \Platformsh\Client\Model\Route[] $routes
     *
     * @return \Platformsh\Cli\Model\Route[]
     */
    public static function fromEnvironmentApi(array $routes)
    {
        $result = [];
        foreach ($routes as $url => $route) {
            $properties = $route->getProperties();
            $properties['original_url'] = $properties['id'];
            $properties['url'] = $url;
            unset($properties['id']);
            $result[] = static::fromData($properties);
        }

        return static::sort($result);
    }

    /**
     * Translates routes found in PLATFORM_ROUTES to Route objects.
     *
     * @param array $routes
     *
     * @return \Platformsh\Cli\Model\Route[]
     */
    public static function fromVariables(array $routes)
    {
        $result = [];

        foreach ($routes as $url => $route) {
            $route['url'] = $url;
            $result[] = static::fromData($route);
        }

        return static::sort($result);
    }

    /**
     * Sorts routes, preferring based on ID or primary status, and preferring shorter URLs with HTTPS.
     *
     * @param Route[] $routes
     *
     * @return Route[]
     */
    private static function sort(array $routes) {
        usort($routes, function (Route $a, Route $b) {
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
