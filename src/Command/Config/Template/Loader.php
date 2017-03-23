<?php

namespace Platformsh\Cli\Command\Config\Template;

use Doctrine\Common\Cache\CacheProvider;
use Twig_Error_Loader;
use Twig_Source;

class Loader implements \Twig_LoaderInterface
{
    protected $cache;

    /**
     * @param \Doctrine\Common\Cache\CacheProvider $cache
     */
    public function __construct(CacheProvider $cache)
    {
        $this->cache = $cache;
    }

    /**
     * {@inheritdoc}
     */
    public function getSourceContext($name)
    {
        if ($this->isUrl($name)) {
            return new Twig_Source($this->download($name), $name);
        }
        $content = file_get_contents($name);
        if ($content === false) {
            throw new Twig_Error_Loader("Failed to load from file: $name");
        }

        return new Twig_Source($content, $name, $name);
    }

    /**
     * {@inheritdoc}
     */
    public function getCacheKey($name)
    {
        return $name;
    }

    /**
     * {@inheritdoc}
     */
    public function isFresh($name, $time)
    {
        return time() - $time < 300;
    }

    /**
     * {@inheritdoc}
     */
    public function exists($name)
    {
        return $this->isUrl($name) ? true : file_exists($name);
    }

    protected function isUrl($name)
    {
        return strpos($name, 'https://') !== false || strpos($name, 'http://') !== false;
    }

    /**
     * @param string $url
     *
     * @throws Twig_Error_Loader
     *
     * @return string
     */
    protected function download($url)
    {
        if ($this->cache->contains($url)) {
            return $this->cache->fetch($url);
        }
        $context = stream_context_create(['http' => ['timeout' => 5]]);
        $content = file_get_contents($url, FILE_BINARY, $context);
        if ($content === false) {
            throw new Twig_Error_Loader("Failed to download file: $url");
        }
        $this->cache->save($url, $content, 300);

        return $content;
    }
}
