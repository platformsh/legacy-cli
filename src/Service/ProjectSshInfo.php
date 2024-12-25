<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Client\Model\Project;

readonly class ProjectSshInfo
{
    public function __construct(private Ssh $ssh) {}

    /**
     * Tests if a project's Git host is external (e.g. Bitbucket, GitHub, GitLab, etc.).
     */
    public function hasExternalGitHost(Project $project): bool
    {
        return $this->ssh->hostIsInternal($project->getGitUrl()) === false;
    }
}
