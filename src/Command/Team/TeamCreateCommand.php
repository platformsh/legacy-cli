<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Team;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Util\Wildcard;
use Platformsh\Client\Exception\ApiResponseException;
use Platformsh\Client\Model\Team\Team;
use Platformsh\Client\Model\UserAccess\ProjectUserAccess;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'team:create', description: 'Create a new team')]
class TeamCreateCommand extends TeamCommandBase
{
    public function __construct(protected ActivityMonitor $activityMonitor, private readonly Api $api, private readonly QuestionHelper $questionHelper, protected readonly Selector $selector, private readonly SubCommandRunner $subCommandRunner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('label', null, InputOption::VALUE_REQUIRED, 'The team label')
            ->addOption('no-check-unique', null, InputOption::VALUE_NONE, 'Do not error if another team exists with the same label in the organization')
            ->addOption('role', 'r', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, "Set the team's project and environment type roles\n"
                . ArrayArgument::SPLIT_HELP . "\n" . Wildcard::HELP)
            ->addOption('output-id', null, InputOption::VALUE_NONE, "Output the new team's ID to stdout (instead of displaying the team info)");
        $this->selector->addOrganizationOptions($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $update = stripos((string) $input->getFirstArgument(), ':u') !== false;
        if ($update) {
            $existingTeam = $this->validateTeamInput($input);
            if (!$existingTeam) {
                return 1;
            }
            $organization = $this->api->getOrganizationById($existingTeam->organization_id);
            if (!$organization) {
                $this->stdErr->writeln(sprintf('Failed to load team organization: <error>%s</error>.', $existingTeam->organization_id));
                return 1;
            }
        } else {
            $existingTeam = null;
            $organization = $this->selectOrganization($input);
            if (!$organization) {
                return 1;
            }
        }

        $label = $input->getOption('label');
        if ($label === null) {
            $label = $this->questionHelper->askInput("Enter the team's label", $existingTeam ? $existingTeam->label : null, [], function ($value) {
                if (empty($value)) {
                    throw new InvalidArgumentException('The label cannot be empty');
                }
                return $value;
            });
            $this->stdErr->writeln('');
        }

        // Ensure the team label is unique (unless --no-check-unique is specified).
        if (!$input->getOption('no-check-unique') && (!$existingTeam || $label !== $existingTeam->label)) {
            $options = [];
            $options['query']['filter[organization_id]'] = $organization->id;
            $client = $this->api->getHttpClient();
            $url = '/teams';
            $pageNumber = 1;
            $progress = new ProgressMessage($this->stdErr);
            while ($url) {
                if ($pageNumber > 1) {
                    $progress->showIfOutputDecorated(sprintf('Loading teams (page %d)...', $pageNumber));
                }
                $result = Team::getCollectionWithParent($url, $client, $options);
                $progress->done();
                /** @var Team $team */
                foreach ($result['items'] as $team) {
                    if ((!$existingTeam || $team->id !== $existingTeam->id) && strcasecmp($team->label, (string) $label) === 0) {
                        $this->stdErr->writeln(sprintf('Another team <error>%s</error> exists in the organization with the same label: <error>%s</error>', $team->id, $label));
                        return 1;
                    }
                }
                $url = $result['collection']->getNextPageUrl();
                $pageNumber++;
            }
        }

        $getProjectRole = fn(array $perms): string => in_array('admin', $perms) ? 'admin' : 'viewer';
        $getEnvTypeRoles = function (array $perms): array {
            $roles = [];
            foreach ($perms as $perm) {
                if (str_contains($perm, ':')) {
                    [$type, $role] = explode(':', $perm, 2);
                    $roles[$type] = $role;
                }
            }
            return $roles;
        };

        /** @var string[] $projectPermissions */
        $projectPermissions = [];
        if ($roleInput = ArrayArgument::getOption($input, 'role')) {
            if ($specifiedProjectRole = $this->getSpecifiedProjectRole($roleInput)) {
                $projectPermissions[] = $specifiedProjectRole;
            }
            foreach ($this->getSpecifiedTypeRoles($roleInput) as $type => $role) {
                if ($role !== 'none') {
                    $projectPermissions[] = $type . ':' . $role;
                }
            }
        } elseif ($input->isInteractive()) {
            $projectRole = $this->showProjectRoleForm($update ? $getProjectRole($existingTeam->project_permissions) : 'viewer', $input);
            $this->stdErr->writeln('');

            $projectPermissions[] = $projectRole;

            if ($projectRole !== 'admin') {
                $environmentTypeRoles = $this->showTypeRolesForm($update ? $getEnvTypeRoles($existingTeam->project_permissions) : [], $input);
                $this->stdErr->writeln('');
                foreach ($environmentTypeRoles as $type => $role) {
                    if ($role !== 'none') {
                        $projectPermissions[] = $type . ':' . $role;
                    }
                }
            }
        } else {
            $projectPermissions = $update ? $existingTeam->project_permissions : [];
        }

        if ($projectPermissions === ['viewer']) {
            $this->stdErr->writeln('At least one environment type role must be specified when the project role is <error>viewer</error>.');
            return 1;
        }

        if (!$update) {
            if (!$this->questionHelper->confirm(\sprintf('Are you sure you want to create a new team <info>%s</info>?', $label))) {
                return 1;
            }
            $this->stdErr->writeln('');
            try {
                $team = $organization->createTeam($label, $projectPermissions);
            } catch (BadResponseException $e) {
                if ($e->getResponse()->getStatusCode() === 409) {
                    $this->stdErr->writeln(\sprintf('A team already exists with the same label: <error>%s</error>', $label));
                    return 1;
                }
                throw $e;
            }
            $this->stdErr->writeln(sprintf('Created team %s in the organization %s', $this->getTeamLabel($team), $this->api->getOrganizationLabel($organization)));
            $this->stdErr->writeln('');
        } else {
            $team = $existingTeam;
            $changesText = [];
            if ($label !== $existingTeam->label) {
                $changesText[] = sprintf('Label: <fg=red>%s</> -> <fg=green>%s</>', $existingTeam->label, $label);
            }
            $currentProjectRole = $getProjectRole($existingTeam->project_permissions);
            $newProjectRole = $getProjectRole($projectPermissions);
            if ($currentProjectRole !== $newProjectRole) {
                $changesText[] = sprintf('Project role: <fg=red>%s</> -> <fg=green>%s</>', $currentProjectRole, $newProjectRole);
            }
            if ($newProjectRole !== 'admin') {
                $currentEnvTypeRoles = $getEnvTypeRoles($existingTeam->project_permissions);
                $newEnvTypeRoles = $getEnvTypeRoles($projectPermissions);
                if ($currentEnvTypeRoles != $newEnvTypeRoles) {
                    foreach (['production', 'staging', 'development'] as $type) {
                        if (!isset($currentEnvTypeRoles[$type]) && !isset($newEnvTypeRoles[$type])) {
                            continue;
                        }
                        if (isset($currentEnvTypeRoles[$type], $newEnvTypeRoles[$type]) && $currentEnvTypeRoles[$type] === $newEnvTypeRoles[$type]) {
                            continue;
                        }
                        $changesText[] = sprintf('Role on environment type %s: <fg=red>%s</> -> <fg=green>%s</>', $type, $currentEnvTypeRoles[$type] ?? '[none]', $newEnvTypeRoles[$type] ?? '[none]');
                    }
                }
            }
            if (empty($changesText)) {
                $this->stdErr->writeln('Nothing to update');
                return 0;
            }
            $this->stdErr->writeln('<options=bold>Summary of changes:</>');
            $this->stdErr->writeln($changesText);
            $this->stdErr->writeln('');
            if (!$this->questionHelper->confirm('Are you sure you want to make these changes?')) {
                return 1;
            }
            $this->stdErr->writeln('');
            try {
                $team->update(['label' => $label, 'project_permissions' => $projectPermissions]);
            } catch (BadResponseException $e) {
                throw ApiResponseException::create($e->getRequest(), $e->getResponse(), $e);
            }
        }

        if ($input->hasOption('output-id') && $input->getOption('output-id')) {
            $output->writeln($team->id);
            return 0;
        }

        return $this->subCommandRunner->run('team:get', ['--team' => $team->id], $this->stdErr);
    }

    /**
     * Show the form for entering the project role.
     *
     * @param string $defaultRole
     * @param InputInterface $input
     *
     * @return string
     */
    private function showProjectRoleForm(string $defaultRole, InputInterface $input): mixed
    {
        $validProjectRoles = ['admin', 'viewer'];

        $this->stdErr->writeln("The team's project role can be " . $this->describeRoles($validProjectRoles) . '.');
        $this->stdErr->writeln('');
        $question = new Question(
            sprintf('Project role (default: %s) <question>%s</question>: ', $defaultRole, $this->describeRoleInput($validProjectRoles)),
            $defaultRole,
        );
        $question->setValidator(fn($answer) => $this->validateProjectRole($answer));
        $question->setMaxAttempts(5);
        $question->setAutocompleterValues(ProjectUserAccess::$projectRoles);

        return $this->questionHelper->ask($input, $this->stdErr, $question);
    }

    /**
     * Expand roles into a list with abbreviations.
     *
     * @param string[] $roles
     *
     * @return string
     */
    private function describeRoles(array $roles): string
    {
        $withInitials = array_map(fn($role): string => sprintf('%s (%s)', $role, substr((string) $role, 0, 1)), $roles);
        $last = array_pop($withInitials);

        return implode(' or ', [implode(', ', $withInitials), $last]);
    }

    /**
     * Describe the input for a roles question, e.g. [a/c/v/n].
     *
     * @param string[] $roles
     *
     * @return string
     */
    private function describeRoleInput(array $roles): string
    {
        return '[' . implode('/', array_map(fn($role): string => substr((string) $role, 0, 1), $roles)) . ']';
    }


    private function validateProjectRole(string $value): string
    {
        return $this->matchRole($value, ['admin', 'viewer']);
    }

    /**
     * Complete a role name based on an array of allowed roles.
     *
     * @param string   $input
     * @param string[] $roles
     *
     * @return string
     */
    private function matchRole(string $input, array $roles): string
    {
        foreach ($roles as $role) {
            if (str_starts_with($role, strtolower($input))) {
                return $role;
            }
        }

        throw new InvalidArgumentException('Invalid role: ' . $input);
    }

    /**
     * Show the form for entering environment type roles.
     *
     * @param array<string, string> $defaultTypeRoles
     * @param InputInterface $input
     *
     * @return array<string, string>
     *   The environment type roles (keyed by type ID) including the user's
     *   answers.
     */
    private function showTypeRolesForm(array $defaultTypeRoles, InputInterface $input): array
    {
        $desiredTypeRoles = [];
        $validRoles = array_merge(ProjectUserAccess::$environmentTypeRoles, ['none']);
        $this->stdErr->writeln("The user's environment type role(s) can be " . $this->describeRoles($validRoles) . '.');
        $initials = $this->describeRoleInput($validRoles);
        $this->stdErr->writeln('');
        foreach (['production', 'staging', 'development'] as $id) {
            $default = $defaultTypeRoles[$id] ?? 'none';
            $question = new Question(
                sprintf('Role on type <info>%s</info> (default: %s) <question>%s</question>: ', $id, $default, $initials),
                $default,
            );
            $question->setValidator(function ($answer) {
                if ($answer === 'q' || $answer === 'quit') {
                    return $answer;
                }

                return $this->validateEnvironmentTypeRole($answer);
            });
            $question->setAutocompleterValues(array_merge($validRoles, ['quit']));
            $question->setMaxAttempts(5);
            $answer = $this->questionHelper->ask($input, $this->stdErr, $question);
            if ($answer === 'q' || $answer === 'quit') {
                break;
            } else {
                $desiredTypeRoles[$id] = $answer;
            }
        }

        return $desiredTypeRoles;
    }

    private function validateEnvironmentTypeRole(string $value): string
    {
        return $this->matchRole($value, array_merge(ProjectUserAccess::$environmentTypeRoles, ['none']));
    }

    /**
     * Extract the specified project role from the list (given in --role).
     *
     * @param string[] $roles
     *
     * @return string|null
     *   The project role, or null if none is specified.
     */
    private function getSpecifiedProjectRole(array $roles): ?string
    {
        foreach ($roles as $role) {
            if (!str_contains($role, ':')) {
                return $this->validateProjectRole($role);
            }
        }

        return null;
    }

    /**
     * Extract the specified environment type roles from the list (given in --role).
     *
     * @param string[] &$roles
     *   An array of role options (e.g. development:contributor).
     *   The $roles array will be modified to remove the values that were used.
     *
     * @return array<string, string>
     *   An array of environment type roles, keyed by environment type ID.
     */
    private function getSpecifiedTypeRoles(array &$roles): array
    {
        $typeRoles = [];
        $typeIds = ['production', 'development', 'staging'];
        foreach ($roles as $key => $role) {
            if (!str_contains($role, ':')) {
                continue;
            }
            [$id, $role] = explode(':', $role, 2);
            $role = $this->validateEnvironmentTypeRole($role);
            // Match type IDs by wildcard.
            $matched = Wildcard::select($typeIds, [$id]);
            if (empty($matched)) {
                $this->stdErr->writeln('No environment type IDs match: <comment>' . $id . '</comment>');
                continue;
            }
            foreach ($matched as $typeId) {
                $typeRoles[$typeId] = $role;
            }
            unset($roles[$key]);
        }

        return $typeRoles;
    }
}
