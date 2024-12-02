<?php
/**
 * @file
 * Finds the correct project and environment for a given URL/string.
 */

namespace Platformsh\Cli\Service;

use Doctrine\Common\Cache\CacheProvider;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Output\ConsoleOutput;

class Identifier
{
    private readonly Config $config;
    private readonly Api $api;
    private $cache;
    private readonly IO $io;

    public function __construct(Config $config = null, Api $api = null, CacheProvider $cache = null, IO $io = null)
    {
        $this->config = $config ?: new Config();
        $this->api = $api ?: new Api();
        $this->cache = $cache ?: CacheFactory::createCacheProvider($this->config);
        $this->io = $io ?: new IO(new ConsoleOutput());
    }

    /**
     * Identify a project from an ID or URL.
     *
     * @param string $url
     *
     * @return array
     *   An array containing keys 'projectId', 'environmentId', 'host', and
     *   'appId'. At least the 'projectId' will be populated.
     */
    public function identify(string $url)
    {
        $result = $this->parseProjectId($url);
        if (empty($result['projectId']) && str_contains($url, '.') && $this->config->has('detection.cluster_header')) {
            $result = $this->identifyFromHeaders($url);
        }
        if (empty($result['projectId'])) {
            throw new InvalidArgumentException('Failed to identify project ID from URL: <error>' . $url . '</error>');
        }

        return $result + ['environmentId' => null, 'host' => null, 'appId' => null];
    }

    /**
     * Parse the project ID and possibly other details from a provided URL.
     *
     * @param string $url
     *   A web UI, API, or public URL of the project.
     *
     * @return array
     */
    private function parseProjectId(string $url): array
    {
        $result = [];

        // If it's a plain alphanumeric string, then it's an ID already.
        if (!preg_match('/\W/', $url)) {
            $result['projectId'] = $url;

            return $result;
        }

        $urlParts = parse_url($url);
        if ($urlParts === false || empty($urlParts['host'])) {
            return $result;
        }

        $this->io->debug('Parsing URL to determine project ID: ' . $url);

        $host = $urlParts['host'];
        $path = isset($urlParts['path']) ? $urlParts['path'] : '';
        $fragment = isset($urlParts['fragment']) ? $urlParts['fragment'] : '';

        $site_domains_pattern = '(' . implode('|', array_map('preg_quote', $this->config->get('detection.site_domains'))) . ')';
        $site_pattern = '/\-\w+\.[a-z]{2}(\-[0-9])?\.' . $site_domains_pattern . '$/';

        if (preg_match($site_pattern, $host)) {
            list($env_project_app,) = explode('.', $host, 2);
            if (($tripleDashPos = strrpos($env_project_app, '---')) !== false) {
                $env_project_app = substr($env_project_app, $tripleDashPos + 3);
            }
            if (($doubleDashPos = strrpos($env_project_app, '--')) !== false) {
                $env_project = substr($env_project_app, 0, $doubleDashPos);
                $result['appId'] = substr($env_project_app, $doubleDashPos + 2);
            } else {
                $env_project = $env_project_app;
            }
            if (($dashPos = strrpos($env_project, '-')) !== false) {
                $result['projectId'] = substr($env_project, $dashPos + 1);
                $result['environmentId'] = substr($env_project, 0, $dashPos);
            }

            return $result;
        }

        if (str_contains($path, '/projects/') || str_contains($fragment, '/projects/')) {
            $result['host'] = $host;
            $result['projectId'] = basename((string) preg_replace('#/projects(/\w+)/?.*$#', '$1', $url));
            if (preg_match('#/environments(/[^/]+)/?.*$#', $url, $matches)) {
                $result['environmentId'] = rawurldecode(basename($matches[1]));
            }

            return $result;
        }

        if ($this->config->has('detection.console_domain')
            && $host === $this->config->get('detection.console_domain')
            && preg_match('#^/[a-z0-9-]+/([a-z0-9-]+)(/([^/]+))?#', $path, $matches)
            // Console uses /-/ to distinguish sub-paths and identifiers.
            && $matches[1] !== '-') {
            $result['projectId'] = $matches[1];
            if (isset($matches[3]) && $matches[3] !== '-') {
                $result['environmentId'] = rawurldecode($matches[3]);
            }

            return $result;
        }

        return $result;
    }

    /**
     * Identify a project and environment from a URL's response headers.
     *
     * @param string $url
     *
     * @return array
     */
    private function identifyFromHeaders(string $url): array
    {
        if (!strpos($url, '.')) {
            throw new \InvalidArgumentException('Invalid URL: ' . $url);
        }
        if (!str_contains($url, '//')) {
            $url = 'https://' . $url;
        }
        $result = ['projectId' => null, 'environmentId' => null];
        $cluster = $this->getClusterHeader($url);
        if (!empty($cluster)) {
            $this->io->debug('Identified project cluster: ' . $cluster);
            list($result['projectId'], $result['environmentId']) = explode('-', $cluster, 2);
        }

        return $result;
    }

    /**
     * Get a project cluster from its URL.
     *
     * @param string $url
     *
     * @return string|false
     */
    private function getClusterHeader(string $url)
    {
        if (!$this->config->has('detection.cluster_header')) {
            return false;
        }
        $cacheKey = 'project-cluster:' . $url;
        $cluster = $this->cache ? $this->cache->fetch($cacheKey) : false;
        if ($cluster === false) {
            $this->io->debug('Making a HEAD request to identify project from URL: ' . $url);
            try {
                $response = $this->api->getExternalHttpClient()
                    ->head($url, [
                    'auth' => false,
                    'timeout' => 5,
                    'connect_timeout' => 5,
                    'allow_redirects' => false,
                ]);
            } catch (RequestException $e) {
                // We can use a failed response, if one exists.
                if ($e->getResponse()) {
                    $response = $e->getResponse();
                } else {
                    $this->io->debug($e->getMessage());

                    return false;
                }
            }
            $cluster = $response->getHeader($this->config->get('detection.cluster_header'));
            $canCache = !empty($cluster)
                || ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300);
            if ($canCache) {
                $this->cache->save($cacheKey, $cluster, 86400);
            }
        }

        return is_array($cluster) ? reset($cluster) : false;
    }
}
