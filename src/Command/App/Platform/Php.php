<?php

namespace Platformsh\Cli\Command\App\Platform;

class Php implements PlatformInterface {

    public function name()
    {
        return 'php';
    }

    public function versions()
    {
        return ['5.6', '7.0'];
    }

    public function getFields()
    {
        return [];
    }

    public function appYamlTemplate()
    {
        // @todo Replace this with a web service call to get a template off of GitHub.

        $template = <<<END
name: {app}
type: {platform}:{version}

build:
    flavor: {flavor}

relationships:
    database: "mysql:mysql"
    solr: "solr:solr"
    redis: "redis:redis"

web:
    locations:
        "/":
            root: "{webroot}"
            passthru: "/{indexFile}"

disk: 2048
mounts:
    "/public/sites/default/files": "shared:files/files"
    "/tmp": "shared:files/tmp"
    "/private": "shared:files/private"

END;
        return $template;

    }

}
