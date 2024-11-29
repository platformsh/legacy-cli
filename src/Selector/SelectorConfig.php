<?php

namespace Platformsh\Cli\Selector;

// TODO clean up in PHP 8+ to use typed properties etc.
use Platformsh\Client\Model\Environment;

class SelectorConfig
{
    private $envRequired = true;
    private $envArgName = 'environment';
    private $chooseProjectText = 'Enter a number to choose a project:';
    private $chooseEnvText = 'Enter a number to choose an environment:';
    private $enterProjectText = 'Enter a project ID';
    private $enterEnvText = 'Enter an environment ID';
    private $selectDefaultEnv = false;
    private $detectCurrentEnv = true;
    /** @var callable|null */
    private $chooseEnvFilter = null;
    private $allowLocalHost = false;
    private $requireApiOnLocal = false;

    /**
     * @param string $envArgName
     * @return SelectorConfig
     */
    public function setEnvArgName($envArgName)
    {
        $this->envArgName = $envArgName;
        return $this;
    }

    /**
     * @param string $chooseProjectText
     * @return SelectorConfig
     */
    public function setChooseProjectText($chooseProjectText)
    {
        $this->chooseProjectText = $chooseProjectText;
        return $this;
    }

    /**
     * @param string $chooseEnvText
     * @return SelectorConfig
     */
    public function setChooseEnvText($chooseEnvText)
    {
        $this->chooseEnvText = $chooseEnvText;
        return $this;
    }

    /**
     * @param string $enterProjectText
     * @return SelectorConfig
     */
    public function setEnterProjectText($enterProjectText)
    {
        $this->enterProjectText = $enterProjectText;
        return $this;
    }

    /**
     * @param string $enterEnvText
     * @return SelectorConfig
     */
    public function setEnterEnvText($enterEnvText)
    {
        $this->enterEnvText = $enterEnvText;
        return $this;
    }

    /**
     * @param callable|null $chooseEnvFilter
     * @return SelectorConfig
     */
    public function setChooseEnvFilter($chooseEnvFilter)
    {
        $this->chooseEnvFilter = $chooseEnvFilter;
        return $this;
    }

    /**
     * @return string
     */
    public function getEnvArgName()
    {
        return $this->envArgName;
    }

    /**
     * @return string
     */
    public function getChooseProjectText()
    {
        return $this->chooseProjectText;
    }

    /**
     * @return string
     */
    public function getChooseEnvText()
    {
        return $this->chooseEnvText;
    }

    /**
     * @return string
     */
    public function getEnterProjectText()
    {
        return $this->enterProjectText;
    }

    /**
     * @return string
     */
    public function getEnterEnvText()
    {
        return $this->enterEnvText;
    }

    /**
     * @return callable|null
     */
    public function getChooseEnvFilter()
    {
        return $this->chooseEnvFilter;
    }

    /**
     * Returns an environment filter to select environments by status.
     *
     * @param string[] $statuses
     *
     * @return callable
     */
    public static function filterEnvsByStatus(array $statuses)
    {
        return function (Environment $e) use ($statuses) {
            return \in_array($e->status, $statuses, true);
        };
    }

    /**
     * Returns an environment filter to select environments that may be active.
     *
     * @return callable
     */
    public static function filterEnvsMaybeActive()
    {
        return function (Environment $e) {
            return \in_array($e->status, ['active', 'dirty'], true) || count($e->getSshUrls()) > 0;
        };
    }

    /**
     * @param bool $selectDefaultEnv
     * @return SelectorConfig
     */
    public function setSelectDefaultEnv($selectDefaultEnv)
    {
        $this->selectDefaultEnv = $selectDefaultEnv;
        return $this;
    }

    /**
     * @param bool $detectCurrentEnv
     * @return SelectorConfig
     */
    public function setDetectCurrentEnv($detectCurrentEnv)
    {
        $this->detectCurrentEnv = $detectCurrentEnv;
        return $this;
    }

    /**
     * @param bool $allowLocalHost
     * @return SelectorConfig
     */
    public function setAllowLocalHost($allowLocalHost)
    {
        $this->allowLocalHost = $allowLocalHost;
        return $this;
    }

    /**
     * @param bool $requireApiOnLocal
     * @return SelectorConfig
     */
    public function setRequireApiOnLocal($requireApiOnLocal)
    {
        $this->requireApiOnLocal = $requireApiOnLocal;
        return $this;
    }

    /**
     * @return bool
     */
    public function shouldSelectDefaultEnv()
    {
        return $this->selectDefaultEnv;
    }

    /**
     * @return bool
     */
    public function shouldDetectCurrentEnv()
    {
        return $this->detectCurrentEnv;
    }

    /**
     * @return bool
     */
    public function shouldAllowLocalHost()
    {
        return $this->allowLocalHost;
    }

    /**
     * @return bool
     */
    public function shouldRequireApiOnLocal()
    {
        return $this->requireApiOnLocal;
    }

    /**
     * @param bool $envRequired
     * @return SelectorConfig
     */
    public function setEnvRequired($envRequired)
    {
        $this->envRequired = $envRequired;
        return $this;
    }

    /**
     * @return bool
     */
    public function isEnvRequired()
    {
        return $this->envRequired;
    }
}
