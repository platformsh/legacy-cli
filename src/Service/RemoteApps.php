<?php

namespace Platformsh\Cli\Service;

use Platformsh\Client\Model\Deployment\WebApp;

/**
 * A service for interacting with remote applications.
 */
class RemoteApps
{
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
