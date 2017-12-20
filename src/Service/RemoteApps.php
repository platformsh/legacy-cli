<?php

namespace Platformsh\Cli\Service;

use Platformsh\Client\Model\Deployment\WebApp;
use Platformsh\Client\Model\Environment;

/**
 * A service for interacting with remote applications.
 */
class RemoteApps
{
    /**
     * Get the config for a remote application.
     *
     * @param \Platformsh\Client\Model\Environment $environment
     * @param string                               $appName
     *
     * @return WebApp
     */
    public function getApp(Environment $environment, $appName)
    {
        return $environment->getCurrentDeployment()->getWebApp($appName);
    }

    /**
     * Get the (normalized) document root of a web app.
     *
     * @param \Platformsh\Client\Model\Deployment\WebApp $app
     *
     * @return string
     */
    public function getDocumentRoot(WebApp $app)
    {
        $web = $app->web;
        if (empty($web['locations'])) {
            // Legacy configuration format.
            if (isset($web['document_root'])) {
                return ltrim($web['document_root'], '/');
            }

            return '';
        }
        $documentRoot = '';
        foreach ($web['locations'] as $path => $location) {
            if (isset($location['root'])) {
                $documentRoot = $location['root'];
            }
            if ($path === '/') {
                break;
            }
        }

        return ltrim($documentRoot, '/');
    }
}
