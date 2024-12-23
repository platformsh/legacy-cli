<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Util\NestedArrayUtil;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

/**
 * Save application state.
 */
class State
{
    /** @var array<string, mixed> */
    protected array $state = [];

    protected bool $loaded = false;

    public function __construct(protected readonly Config $config) {}

    /**
     * Gets a state value.
     *
     * @return mixed|false
     *   The value, or false if the value does not exist.
     */
    public function get(string $key): mixed
    {
        $this->load();
        $value = NestedArrayUtil::getNestedArrayValue($this->state, explode('.', $key), $exists);

        return $exists ? $value : false;
    }

    /**
     * Sets a state value.
     */
    public function set(string $key, mixed $value, bool $save = true): void
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
     * Saves state.
     */
    public function save(): void
    {
        (new SymfonyFilesystem())->dumpFile(
            $this->getFilename(),
            (string) json_encode($this->state),
        );
    }

    /**
     * Load state.
     */
    protected function load(): void
    {
        if (!$this->loaded) {
            $filename = $this->getFilename();
            if (file_exists($filename)) {
                $content = (string) file_get_contents($filename);
                $this->state = json_decode($content, true) ?: [];
            }
            $this->loaded = true;
        }
    }

    /**
     * @return string
     */
    protected function getFilename(): string
    {
        return $this->config->getWritableUserDir() . DIRECTORY_SEPARATOR . $this->config->getWithDefault('application.user_state_file', 'state.json');
    }
}
