<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Doctrine\Common\Cache\CacheProvider;
use Platformsh\Client\Exception\EnvironmentStateException;
use Platformsh\Client\Exception\GitObjectTypeException;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Git\Blob;
use Platformsh\Client\Model\Git\Commit;
use Platformsh\Client\Model\Git\Tree;
use Platformsh\Client\Model\Project;

/**
 * Service helping to read from the Git Data API.
 */
class GitDataApi
{
    public const COMMIT_SYNTAX_HELP = 'This can also accept "HEAD", and caret (^) or tilde (~) suffixes for parent commits.';

    private readonly Api $api;
    private readonly CacheProvider $cache;

    public function __construct(
        ?Api $api = null,
        ?CacheProvider $cache = null,
    ) {
        $this->api = $api ?: new Api();
        $this->cache = $cache ?: CacheFactory::createCacheProvider(new Config());
    }

    /**
     * Parse the parents in a Git commit spec.
     *
     * @param string $sha
     *
     * @return int[]
     *   A list of parents.
     */
    private function parseParents(string $sha): array
    {
        if (!strpos($sha, '^') && !strpos($sha, '~')) {
            return [];
        }
        preg_match_all('#[\^~][0-9]*#', $sha, $matches);
        $parents = [];
        foreach ($matches[0] as $match) {
            $sign = $match[0];
            $number = intval(substr($match, 1) ?: 1);
            if ($sign === '~') {
                for ($i = 1; $i <= $number; $i++) {
                    $parents[] = 1;
                }
            } elseif ($sign === '^') {
                $parents[] = $number;
            }
        }

        return $parents;
    }

    /**
     * Gets a Git Commit object for an environment.
     */
    public function getCommit(Environment $environment, ?string $sha = null): false|Commit
    {
        $sha = $this->normalizeSha($environment, $sha);

        $parents = $this->parseParents($sha);
        $sha = preg_replace('/[\^~].*$/', '', $sha);
        if ($sha === '') {
            return false;
        }

        // Get the first commit.
        $commit = $this->getCommitByShaHash($environment, $sha);

        // Fetch parent commits recursively.
        while ($commit !== false && count($parents)) {
            $parent = array_shift($parents);
            if (isset($commit->parents[$parent - 1])) {
                $commit = $this->getCommitByShaHash($environment, $commit->parents[$parent - 1]);
            } else {
                return false;
            }
        }

        return $commit;
    }

    /**
     * Get a specific commit from the API.
     *
     * @param Environment $environment
     * @param string $sha The "pure" commit SHA hash.
     *
     * @return Commit|false
     */
    private function getCommitByShaHash(Environment $environment, string $sha): Commit|false
    {
        $cacheKey = $environment->project . ':' . $sha;
        $client = $this->api->getHttpClient();
        if ($cached = $this->cache->fetch($cacheKey)) {
            return new Commit($cached['data'], $cached['uri'], $client, true);
        }
        $baseUrl = Project::getProjectBaseFromUrl($environment->getUri()) . '/git/commits';
        $commit = Commit::get($sha, $baseUrl, $client);
        if ($commit === false) {
            return false;
        }
        $data = $commit->getData();
        // No need to cache API metadata.
        if (isset($data['_links']['self']['meta'])) {
            unset($data['_links']['self']['meta']);
        }
        $this->cache->save($cacheKey, [
            'data' => $data,
            'uri' => $baseUrl,
        ], 0);

        return $commit;
    }

    /**
     * Normalizes a commit SHA for API and caching purposes.
     */
    private function normalizeSha(Environment $environment, ?string $sha = null): string
    {
        if ($sha === null) {
            return $this->getHeadSha($environment);
        }
        if (str_starts_with($sha, 'HEAD')) {
            $sha = $this->getHeadSha($environment) . substr($sha, 4);
        }

        return $sha;
    }

    /**
     * Returns the HEAD commit of an environment.
     *
     * @param Environment $environment
     *
     * @return string
     */
    private function getHeadSha(Environment $environment): string
    {
        if ($environment->head_commit === null) {
            throw new EnvironmentStateException('No commit(s) found. The environment is empty.', $environment);
        }
        return $environment->head_commit;
    }

    /**
     * Read a file in the environment, using the Git Data API.
     *
     * @param string      $filename
     * @param Environment $environment
     * @param string|null $commitSha
     *
     * @throws \RuntimeException on error.
     *
     * @return string|false
     *   The raw contents of the file, or false if the file is not found.
     */
    public function readFile(string $filename, Environment $environment, ?string $commitSha = null): string|false
    {
        $commitSha = $this->normalizeSha($environment, $commitSha);
        $cacheKey = implode(':', ['raw', $environment->project, $filename, $commitSha]);
        $data = $this->cache->fetch($cacheKey);
        if (!is_array($data)) {
            $object = $this->getObject($filename, $environment, $commitSha);
            if ($object instanceof Tree) {
                throw new GitObjectTypeException('The requested file is a directory', $filename);
            } elseif ($object === false) {
                $data['raw'] = false;
            } else {
                $data['raw'] = $object->getRawContent();
                // Cache the file if it is smaller than 100 KiB.
                if (strlen($data['raw']) < 102400) {
                    $this->cache->save($cacheKey, $data);
                }
            }
        }

        return $data['raw'];
    }

    /**
     * Fetches a blob or tree object in the environment, using the Git Data API.
     *
     * @param string      $filename
     * @param Environment $environment
     * @param string|null $commitSha
     *
     * @throws \RuntimeException on error.
     *
     * @return Tree|Blob|false
     *   The object or false if not found.
     */
    public function getObject(string $filename, Environment $environment, ?string $commitSha = null): Tree|Blob|false
    {
        $commitSha = $this->normalizeSha($environment, $commitSha);
        $cacheKey = implode(':', ['obj', $environment->project, $filename, $commitSha]);
        if ($this->cache->contains($cacheKey)) {
            $data = $this->cache->fetch($cacheKey);
            if (isset($data['type']) && $data['type'] === 'not_found') {
                return false;
            }
            if (isset($data['data'], $data['type'], $data['uri'])) {
                $className = $data['type'] === 'tree' ? Tree::class : Blob::class;
                return new $className($data['data'], $data['uri'], $this->api->getHttpClient());
            }
        }
        $parentTree = $this->getTree($environment, \dirname($filename), $commitSha);
        $object = $parentTree ? $parentTree->getObject(\basename($filename)) : false;
        if ($object) {
            $toCache = [
                'data' => $object->getData(),
                'type' => $object instanceof Tree ? 'tree' : 'blob',
                'uri' => $object->getUri(),
            ];
        } else {
            $toCache = ['type' => 'not_found'];
        }
        $this->cache->save($cacheKey, $toCache);
        return $object;
    }

    private function normalizePath(string $path): string
    {
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        $path = trim($path, '/');
        return $path === '' ? '.' : $path;
    }

    /**
     * Get a Git Tree object (a repository directory) for an environment.
     *
     * @param Environment $environment
     * @param string      $path
     * @param string|null $commitSha
     * @param bool $onlyUseCache
     *
     * @return Tree|false
     */
    public function getTree(Environment $environment, string $path = '.', ?string $commitSha = null, bool $onlyUseCache = false): Tree|false
    {
        $normalizedSha = $this->normalizeSha($environment, $commitSha);
        $normalizedPath = $this->normalizePath($path);
        $cacheKey = implode(':', ['tree', $environment->project, $normalizedPath, $normalizedSha]);
        $data = $this->cache->fetch($cacheKey);
        if (!is_array($data)) {
            if ($onlyUseCache) {
                return false;
            }

            // Check if the parent tree is already cached, to avoid re-fetching
            // each parent.
            $parentPath = \dirname($normalizedPath);
            $parentTree = false;
            if ($parentPath !== $normalizedPath && $parentPath !== '.') {
                $parentTree = $this->getTree($environment, $parentPath, $normalizedSha, true);
            }

            if ($parentTree) {
                $tree = $parentTree->getTree(basename($normalizedPath));
            } else {
                if (!$commit = $this->getCommit($environment, $normalizedSha)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Commit not found: %s',
                        $commitSha,
                    ));
                }
                if (!$rootTree = $commit->getTree()) {
                    // This is unlikely to happen.
                    throw new \RuntimeException('Failed to get tree for commit: ' . $commit->id);
                }
                $tree = $rootTree->getTree($normalizedPath);
            }

            $this->cache->save($cacheKey, [
                'tree' => $tree ? $tree->getData() : null,
                'uri' => $tree ? $tree->getUri() : null,
            ]);
        } elseif (empty($data['tree'])) {
            return false;
        } else {
            $tree = new Tree($data['tree'], $data['uri'], $this->api->getHttpClient(), true);
        }

        return $tree;
    }
}
