<?php
namespace Platformsh\Cli\Command\App\Platform;

interface PlatformInterface {
    public function name();

    public function versions();

    public function getFields();

    public function appYamlTemplate();
}
