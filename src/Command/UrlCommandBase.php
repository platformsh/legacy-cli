<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Util\UrlUtil;

abstract class UrlCommandBase extends CommandBase
{
    protected $urlUtil;

    public function __construct($name = null)
    {
        $this->urlUtil = new UrlUtil();
        parent::__construct($name);
    }
}
