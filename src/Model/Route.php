<?php

namespace Platformsh\Cli\Model;

use Platformsh\Client\DataStructure\ReadOnlyStructureTrait;

/**
 * @property-read string $original_url
 * @property-read string $type
 * @property-read string $upstream
 * @property-read string $to
 */
class Route
{
    use ReadOnlyStructureTrait;

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

        return $result;
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

        return $result;
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

        return $result;
    }
}
