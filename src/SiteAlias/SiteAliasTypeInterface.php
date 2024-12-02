<?php

namespace Platformsh\Cli\SiteAlias;

use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;

interface SiteAliasTypeInterface
{
    /**
     * Create an alias file.
     *
     * @param Project $project
     * @param string                                   $aliasGroup
     * @param LocalApplication[] $apps
     * @param Environment[] $environments
     * @param string|null                              $previousGroup
     *
     * @throws \RuntimeException
     *
     * @return bool Whether any aliases have been created.
     */
    public function createAliases(Project $project, $aliasGroup, array $apps, array $environments, $previousGroup = null);

    /**
     * Delete old alias file(s).
     *
     * @param string $group
     */
    public function deleteAliases($group);
}
