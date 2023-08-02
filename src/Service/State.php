<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Util\NestedArrayUtil;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

/**
 * Save application state.
 */
class State
{
    protected $config;

    protected $state = [];

    protected $loaded = false;

    /**
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * @param string $key
     *
     * @return mixed|false
     *   The value, or false if the value does not exist.
     */
    public function get($key)
    {
        $this->load();
        $value = NestedArrayUtil::getNestedArrayValue($this->state, explode('.', $key), $exists);

        return $exists ? $value : false;
    }

    /**
     * Set a state value.
     *
     * @param string $key
     * @param mixed  $value
     * @param bool   $save
     */
    public function set($key, $value, $save = true)
    {
        $this->load();
        $parents = explode('.', $key);
        $current = NestedArrayUtil::getNestedArrayValue($this->state, $parents);
        if ($current !== $value) {
            NestedArrayUtil::setNestedArrayValue($this->state, $parents, $value);
            if ($save) {
                $this->save();
            }
        }
    }

    /**
     * Save state.
     */
    public function save()
    {
        (new SymfonyFilesystem())->dumpFile(
            $this->getFilename(),
            json_encode($this->state)
        );
    }

    /**
     * Load state.
     */
    protected function load()
    {
        if (!$this->loaded) {
            $filename = $this->getFilename();
            if (file_exists($filename)) {
                $content = file_get_contents($filename);
                $this->state = json_decode($content, true) ?: [];
            }
            $this->loaded = true;
        }
    }

    /**
     * @return string
     */
    protected function getFilename()
    {
        return $this->config->getWritableUserDir() . DIRECTORY_SEPARATOR . $this->config->getWithDefault('application.user_state_file', 'state.json');
    }
}
