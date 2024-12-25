<?php

declare(strict_types=1);

namespace Platformsh\Cli\SiteAlias;

use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;

interface SiteAliasTypeInterface
{
    /**
     * Creates an alias file.
     *
     * @param LocalApplication[] $apps
     * @param Environment[] $environments
     *
     * @return bool Whether any aliases have been created.
     *
     * @throws \RuntimeException
     */
    public function createAliases(Project $project, string $aliasGroup, array $apps, array $environments, ?string $previousGroup = null): bool;

    /**
     * Delete old alias file(s).
     *
     * @param string $group
     */
    public function deleteAliases(string $group): void;
}
