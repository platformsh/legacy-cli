<?php

namespace CommerceGuys\Platform\Cli\Local\Toolstack;

use CommerceGuys\Platform\Cli\Helper\FilesystemHelper;

abstract class ToolstackBase implements ToolstackInterface
{

    protected $settings = array();
    protected $appRoot;
    protected $projectRoot;
    protected $buildDir;
    protected $absoluteLinks = false;

    /** @var FilesystemHelper */
    protected $fsHelper;

    public function __construct()
    {
        $this->fsHelper = new FilesystemHelper();
    }

    public function prepareBuild($appRoot, $projectRoot, array $settings)
    {
        $this->appRoot = $appRoot;
        $this->projectRoot = $projectRoot;
        $this->settings = $settings;

        $buildName = date('Y-m-d--H-i-s') . '--' . $settings['environmentId'];
        $this->buildDir = $projectRoot . '/builds/' . $buildName;

        $this->absoluteLinks = !empty($settings['absoluteLinks']);
        $this->fsHelper->setRelativeLinks(!$this->absoluteLinks);

        return $this;
    }

}
