<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\AccessApi;
use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Client\Model\Environment;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Cli\Util\Wildcard;
use Platformsh\Client\Model\EnvironmentType;
use Platformsh\Client\Model\Invitation\AlreadyInvitedException;
use Platformsh\Client\Model\Invitation\Permission;
use Platformsh\Client\Model\ProjectAccess;
use Platformsh\Client\Model\UserAccess\ProjectUserAccess;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'user:add', description: 'Add a user to the project')]
class UserAddCommand extends CommandBase
{
    public function __construct(
        private readonly AccessApi $accessApi,
        protected readonly ActivityMonitor $activityMonitor,
        private readonly Api               $api,
        private readonly Config            $config,
        private readonly Io                $io,
        private readonly QuestionHelper    $questionHelper,
        protected readonly Selector        $selector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::OPTIONAL, "The user's email address");

        $this->addRoleOption();

        $this->addOption('force-invite', null, InputOption::VALUE_NONE, 'Send an invitation, even if one has already been sent');

        $this->selector->addProjectOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());

        $this->addExample('Add Alice as a project admin', 'alice@example.com -r admin');
        $this->addExample('Add Bob as a viewer on the "production" environment type, and a contributor on "development" environments', 'bob@example.com -r production:v -r development:c');
        $this->addExample('Add Charlie as viewer on "production" and "development"', 'charlie@example.com -r prod%:v -r dev%:v');
    }

    /**
     * Adds the --role (-r) option to the command.
     */
    protected function addRoleOption(): void
    {
        $this->addOption(
            'role',
            'r',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            "The user's project role ('admin' or 'viewer') or environment type role (e.g. 'staging:contributor' or 'production:viewer')."
            . "\nTo remove a user from an environment type, set the role as 'none'."
            . "\nThe % or * characters can be used as a wildcard for the environment type, e.g. '%:viewer' to give the user the 'viewer' role on all types."
            . "\nThe role can be abbreviated, e.g. 'production:v'.",
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);
        $project = $selection->getProject();

        $hasOutput = false;

        $environmentTypes = $this->api->getEnvironmentTypes($project);

        // Process the --role option.
        $roleInput = ArrayArgument::getOption($input, 'role');
        $specifiedProjectRole = $this->getSpecifiedProjectRole($roleInput);
        $specifiedTypeRoles = $this->getSpecifiedTypeRoles($roleInput, $environmentTypes);
        if (!empty($roleInput)) {
            $specifiedEnvironmentRoles = $this->getSpecifiedEnvironmentRoles($roleInput, $this->api->getEnvironments($project));
        }
        if ($specifiedProjectRole === ProjectUserAccess::ROLE_ADMIN && (!empty($specifiedTypeRoles) || !empty($specifiedEnvironmentRoles))) {
            $this->warnProjectAdminConflictingRoles();
            return 1;
        }

        // For backwards compatibility, convert ID-based roles to type-based.
        if (!empty($specifiedEnvironmentRoles)) {
            $this->stdErr->writeln('<fg=yellow;options=bold>Warning:</>');
            $this->stdErr->writeln('<fg=yellow>Access control is now based on environment types, not individual environments.</>');
            $this->stdErr->writeln('<fg=yellow>Please use the environment type to specify roles.</>');
            // In interactive use, error out. In non-interactive use, warn but continue (for backwards compatibility).
            if ($input->isInteractive()) {
                // Try to show an example of how to use type-based roles.
                $environments = $this->api->getEnvironments($project);
                $converted = $this->convertEnvironmentRolesToTypeRoles($specifiedEnvironmentRoles, $specifiedTypeRoles, $environments, new NullOutput());
                if ($converted !== false) {
                    $this->stdErr->writeln('');
                    $this->stdErr->writeln('For example, use:');
                    $exampleRoleArgs = [];
                    if ($specifiedProjectRole !== null) {
                        $exampleRoleArgs[] = $specifiedProjectRole;
                    }
                    foreach ($converted as $typeId => $typeRole) {
                        $exampleRoleArgs[] = "$typeId:$typeRole";
                    }
                    $this->stdErr->writeln(\sprintf('  <comment>--role %s</comment>', implode(',', $exampleRoleArgs)));
                }
                return 1;
            }
            $this->stdErr->writeln('');
            // Convert the list of environment-based roles to type-based roles.
            // Refresh the environments to check their types more accurately.
            $environments = $this->api->getEnvironments($project, true);
            $converted = $this->convertEnvironmentRolesToTypeRoles($specifiedEnvironmentRoles, $specifiedTypeRoles, $environments, $this->stdErr);
            if ($converted === false) {
                return 1;
            }
            $specifiedTypeRoles = $converted;
            unset($specifiedEnvironmentRoles);
            $this->stdErr->writeln('');
        }

        // Process the [email] argument.
        // This can be an email address or a user ID.
        // When adding a new user, it must be a valid email address.
        $email = null;
        $update = stripos((string) $input->getFirstArgument(), ':u');
        if ($emailOrId = $input->getArgument('email')) {
            $selection = $this->accessApi->loadProjectUser($project, $emailOrId);
            if (!$selection) {
                if ($update) {
                    throw new InvalidArgumentException('User not found: ' . $emailOrId);
                }
                $email = filter_var($emailOrId, FILTER_VALIDATE_EMAIL);
                if ($email === false) {
                    throw new InvalidArgumentException('Invalid email address: ' . $emailOrId);
                }
            }
        } elseif (!$input->isInteractive()) {
            throw new InvalidArgumentException('An email address is required (in non-interactive mode).');
        } elseif ($update) {
            $userId = $this->questionHelper->choose($this->accessApi->listUsers($project), 'Enter a number to choose a user to update:');
            $hasOutput = true;
            $selection = $this->accessApi->loadProjectUser($project, $userId);
            if (!$selection) {
                throw new InvalidArgumentException('User not found: ' . $userId);
            }
        } else {
            $question = new Question("Enter the user's email address: ");
            $question->setValidator(function (?string $answer) {
                if (empty($answer)) {
                    throw new InvalidArgumentException('An email address is required.');
                }
                if (!$filtered = filter_var($answer, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Invalid email address: ' . $answer);
                }

                return $filtered;
            });
            $question->setMaxAttempts(5);
            $email = $this->questionHelper->ask($input, $this->stdErr, $question);
            $hasOutput = true;
            // A user may or may not already exist with this email address.
            $selection = $this->accessApi->loadProjectUser($project, $email);
        }

        $existingTypeRoles = [];
        $existingProjectRole = null;
        $existingUserLabel = null;
        $existingUserId = null;

        if ($selection instanceof ProjectAccess) {
            $existingUserId = $selection->id;
            $existingUserLabel = $this->accessApi->getUserLabel($selection, true);
            $existingProjectRole = $selection->role;
            $existingTypeRoles = $this->getTypeRoles($selection, $environmentTypes);
            $email = $this->accessApi->legacyUserInfo($selection)['email'];
        } elseif ($selection) {
            $existingUserId = $selection->user_id;
            $existingUserLabel = $this->accessApi->getUserLabel($selection, true);
            $existingProjectRole = $selection->getProjectRole();
            $existingTypeRoles = $selection->getEnvironmentTypeRoles();
            $email = $selection->getUserInfo()->email;
        }

        if ($existingUserId !== null) {
            $this->io->debug(sprintf('User %s found with user ID: %s', $email, $existingUserId));
        }

        // Exit if the user is the owner already.
        if ($existingUserId !== null && $existingUserId === $project->owner) {
            if ($hasOutput) {
                $this->stdErr->writeln('');
            }

            $this->stdErr->writeln(sprintf('The user %s is the owner of %s.', $existingUserLabel, $this->api->getProjectLabel($project)));
            if ($specifiedProjectRole || $specifiedTypeRoles) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln("<comment>The project owner's role(s) cannot be changed.</comment>");

                return 1;
            }

            return 0;
        }

        // If the user already exists, print a summary of their roles on the
        // project and environments.
        if ($existingUserId !== null) {
            if ($hasOutput) {
                $this->stdErr->writeln('');
            }

            $this->stdErr->writeln(sprintf('Current role(s) of %s on %s:', $existingUserLabel, $this->api->getProjectLabel($project)));
            $this->stdErr->writeln(sprintf('  Project role: <info>%s</info>', $existingProjectRole));
            if ($existingProjectRole !== ProjectUserAccess::ROLE_ADMIN) {
                foreach ($environmentTypes as $type) {
                    $role = $existingTypeRoles[$type->id] ?? '[none]';
                    $this->stdErr->writeln(sprintf('    Role on environment type <info>%s</info>: %s', $type->id, $role));
                }
            }
            $hasOutput = true;
        }

        // Resolve or merge the project role.
        $desiredProjectRole = $specifiedProjectRole ?: ($existingProjectRole ?: ProjectUserAccess::ROLE_VIEWER);
        $provideProjectForm = !$input->getOption('role') && $input->isInteractive();
        if ($provideProjectForm) {
            if ($hasOutput) {
                $this->stdErr->writeln('');
            }
            $desiredProjectRole = $this->showProjectRoleForm($desiredProjectRole, $input);
            $hasOutput = true;
        }

        $desiredTypeRoles = [];
        $provideEnvironmentTypeForm = $input->isInteractive()
            && $desiredProjectRole !== ProjectUserAccess::ROLE_ADMIN
            && !$specifiedTypeRoles;
        // Resolve or merge the environment type role(s).
        if ($desiredProjectRole !== ProjectUserAccess::ROLE_ADMIN) {
            foreach ($environmentTypes as $type) {
                $id = $type->id;
                if (isset($specifiedTypeRoles[$id])) {
                    $desiredTypeRoles[$id] = $specifiedTypeRoles[$id];
                } elseif (isset($existingTypeRoles[$id])) {
                    $desiredTypeRoles[$id] = $existingTypeRoles[$id];
                }
            }
        }
        if ($provideEnvironmentTypeForm) {
            if ($hasOutput) {
                $this->stdErr->writeln('');
            }
            $desiredTypeRoles = $this->showTypeRolesForm($desiredTypeRoles, $environmentTypes, $input);
            $hasOutput = true;
        }

        // Build a list of the changes that are going to be made.
        $changesText = [];
        if ($existingUserId !== null) {
            if ($existingProjectRole !== $desiredProjectRole) {
                $changesText[] = sprintf('Project role: <error>%s</error> -> <info>%s</info>', $existingProjectRole, $desiredProjectRole);
            }
        } else {
            $changesText[] = sprintf('Project role: <info>%s</info>', $desiredProjectRole);
        }
        $typeChanges = [];
        if ($desiredProjectRole !== ProjectUserAccess::ROLE_ADMIN) {
            if ($desiredTypeRoles) {
                foreach ($environmentTypes as $environmentType) {
                    $id = $environmentType->id;
                    $new = $desiredTypeRoles[$id] ?? 'none';
                    if ($existingTypeRoles) {
                        $existing = $existingTypeRoles[$id] ?? 'none';
                        if ($existing !== $new) {
                            $changesText[] = sprintf('  Role on type <info>%s</info>: <error>%s</error> -> <info>%s</info>', $id, $existing, $new);
                            $typeChanges[$id] = $new;
                        }
                    } elseif ($new !== 'none') {
                        $changesText[] = sprintf('  Role on type <info>%s</info>: <info>%s</info>', $id, $new);
                        $typeChanges[$id] = $new;
                    }
                }
            }
        }

        // Exit early if there are no changes to make.
        if (empty($changesText)) {
            if ($provideProjectForm || $provideEnvironmentTypeForm) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('There are no changes to make.');
            }

            return 0;
        }

        // Filter out environment type roles of 'none' from the list.
        $desiredTypeRoles = array_filter($desiredTypeRoles, fn($role): bool => $role !== 'none');

        // Add a new line if there has already been output.
        if ($hasOutput) {
            $this->stdErr->writeln('');
        }

        // Require project non-admins to be added to at least one environment.
        if ($desiredProjectRole === ProjectUserAccess::ROLE_VIEWER && !$desiredTypeRoles) {
            $this->stdErr->writeln('<error>No environment types selected.</error>');
            $this->stdErr->writeln('A non-admin user must be added to at least one environment type.');

            if ($existingUserId !== null) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf(
                    'To delete the user, run: <info>%s user:delete %s</info>',
                    $this->config->getStr('application.executable'),
                    OsUtil::escapeShellArg($email),
                ));
            }

            return 1;
        }

        // Prevent changing environment access for project admins.
        if ($desiredProjectRole === ProjectUserAccess::ROLE_ADMIN && $specifiedTypeRoles) {
            $this->warnProjectAdminConflictingRoles();
            return 1;
        }

        // Print a summary of the changes that are about to be made.
        if ($existingUserId !== null) {
            $this->stdErr->writeln('Summary of changes:');
        } else {
            $this->stdErr->writeln(sprintf('Adding the user <info>%s</info> to %s:', $email, $this->api->getProjectLabel($project)));
        }
        foreach ($changesText as $change) {
            $this->stdErr->writeln('  ' . $change);
        }
        $this->stdErr->writeln('');

        // Ask for confirmation.
        if ($existingUserId !== null) {
            if (!$this->questionHelper->confirm('Are you sure you want to make these change(s)?')) {
                return 1;
            }
        } else {
            if ($this->config->getBool('warnings.project_users_billing')) {
                $this->stdErr->writeln('<comment>Adding users can result in additional charges.</comment>');
                $this->stdErr->writeln('');
            }
            if (!$this->questionHelper->confirm('Are you sure you want to add this user?')) {
                return 1;
            }
        }
        $this->stdErr->writeln('');

        // If the user does not already exist on the project, then use the Invitations API.
        if ($existingUserId === null) {
            $this->stdErr->writeln('Inviting the user to the project...');
            $permissions = [];
            foreach ($desiredTypeRoles as $type => $role) {
                $permissions[] = new Permission($type, $role);
            }
            try {
                $project->inviteUserByEmail($email, $desiredProjectRole, [], $input->getOption('force-invite'), $permissions);
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf('An invitation has been sent to <info>%s</info>', $email));
            } catch (AlreadyInvitedException $e) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf('An invitation has already been sent to <info>%s</info>', $e->getEmail()));
                if ($this->questionHelper->confirm('Do you want to send this invitation anyway?')) {
                    $project->inviteUserByEmail($email, $desiredProjectRole, [], true, $permissions);
                    $this->stdErr->writeln('');
                    $this->stdErr->writeln(sprintf('A new invitation has been sent to <info>%s</info>', $email));
                }
            }

            return 0;
        }

        $activities = [];
        if ($selection instanceof ProjectUserAccess) {
            $permissions = [$desiredProjectRole];
            foreach ($desiredTypeRoles as $typeId => $role) {
                $permissions[] = sprintf('%s:%s', $typeId, $role);
            }
            if ($permissions != $selection->permissions) {
                $this->stdErr->writeln("Updating the user's project access...");
                $this->io->debug('New permissions: ' . implode(', ', $permissions));
                $selection->update(['permissions' => $permissions]);
                $this->stdErr->writeln('Access was updated successfully.');
            } else {
                $this->stdErr->writeln('No changes to make');
                $this->io->debug('Permissions match: ' . implode(', ', $permissions));
            }
        } elseif ($selection instanceof ProjectAccess) {
            // Make the desired changes at the project level.
            if ($existingProjectRole !== $desiredProjectRole) {
                $this->stdErr->writeln("Setting the user's project role to: $desiredProjectRole");
                $result = $selection->update(['role' => $desiredProjectRole]);
                $activities = $result->getActivities();
            }

            // Make the desired changes at the environment type level.
            if ($desiredProjectRole !== ProjectAccess::ROLE_ADMIN) {
                foreach ($typeChanges as $typeId => $role) {
                    $type = $project->getEnvironmentType($typeId);
                    if (!$type) {
                        $this->stdErr->writeln('Environment type not found: <comment>' . $typeId . '</comment>');
                        continue;
                    }
                    $access = $type->getUser($existingUserId);
                    if ($role === 'none') {
                        if ($access) {
                            $this->stdErr->writeln("Removing the user from the environment type <info>$typeId</info>");
                            $result = $access->delete();
                        } else {
                            continue;
                        }
                    } elseif ($access) {
                        if ($access->role === $role) {
                            continue;
                        }
                        $this->stdErr->writeln("Setting the user's role on the environment type <info>$typeId</info> to: $role");
                        $result = $access->update(['role' => $role]);
                    } else {
                        $this->stdErr->writeln("Adding the user to the environment type: <info>$typeId</info>");
                        $result = $type->addUser($existingUserId, $role);
                    }
                    $activities = array_merge($activities, $result->getActivities());
                }
            }
        }

        // Wait for activities to complete.
        if ($activities && $this->activityMonitor->shouldWait($input)) {
            $activityMonitor = $this->activityMonitor;
            if (!$activityMonitor->waitMultiple($activities, $project)) {
                return 1;
            }
        } elseif (!$this->accessApi->centralizedPermissionsEnabled()) {
            $this->api->redeployWarning();
        }

        return 0;
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private function validateProjectRole(string $value): string
    {
        return $this->matchRole($value, ProjectUserAccess::$projectRoles);
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private function validateEnvironmentRole(string $value): string
    {
        return $this->matchRole($value, array_merge(ProjectUserAccess::$environmentTypeRoles, ['none']));
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
        $this->stdErr->writeln("The user's project role can be " . $this->describeRoles(ProjectUserAccess::$projectRoles) . '.');
        $this->stdErr->writeln('');
        $question = new Question(
            sprintf('Project role (default: %s) <question>%s</question>: ', $defaultRole, $this->describeRoleInput(ProjectUserAccess::$projectRoles)),
            $defaultRole,
        );
        $question->setValidator(fn($answer) => $this->validateProjectRole($answer));
        $question->setMaxAttempts(5);
        $question->setAutocompleterValues(ProjectUserAccess::$projectRoles);

        return $this->questionHelper->ask($input, $this->stdErr, $question);
    }

    /**
     * Load the user's roles on the project's environment types.
     *
     * @param ProjectAccess $projectAccess
     * @param EnvironmentType[] $environmentTypes
     *
     * @return array<string, string>
     */
    private function getTypeRoles(ProjectAccess $projectAccess, array $environmentTypes): array
    {
        if ($projectAccess->role === ProjectAccess::ROLE_ADMIN) {
            return [];
        }

        $progress = new ProgressBar(isset($this->stdErr) && $this->stdErr->isDecorated() ? $this->stdErr : new NullOutput());
        $progress->setMessage('Loading environment type access...');
        $progress->setFormat('%message% %current%/%max%');
        $progress->start(count($environmentTypes));

        $typeRoles = [];
        foreach ($environmentTypes as $type) {
            if (!$type->operationAvailable('access')) {
                continue;
            }
            if ($access = $type->getUser($projectAccess->id)) {
                $typeRoles[$type->id] = $access->role;
            }
            $progress->advance();
        }
        $progress->finish();
        $progress->clear();

        return $typeRoles;
    }

    /**
     * Show the form for entering environment type roles.
     *
     * @param array<string, string> $defaultTypeRoles
     * @param EnvironmentType[] $environmentTypes
     * @param InputInterface $input
     *
     * @return array<string, string>
     *   The environment type roles (keyed by type ID) including the user's
     *   answers.
     */
    private function showTypeRolesForm(array $defaultTypeRoles, array $environmentTypes, InputInterface $input): array
    {
        $desiredTypeRoles = [];
        $validRoles = array_merge(ProjectUserAccess::$environmentTypeRoles, ['none']);
        $this->stdErr->writeln("The user's environment type role(s) can be " . $this->describeRoles($validRoles) . '.');
        $initials = $this->describeRoleInput($validRoles);
        $this->stdErr->writeln('');
        foreach ($environmentTypes as $environmentType) {
            $id = $environmentType->id;
            $default = $defaultTypeRoles[$id] ?? 'none';
            $question = new Question(
                sprintf('Role on type <info>%s</info> (default: %s) <question>%s</question>: ', $id, $default, $initials),
                $default,
            );
            $question->setValidator(function ($answer) {
                if ($answer === 'q' || $answer === 'quit') {
                    return $answer;
                }

                return $this->validateEnvironmentRole($answer);
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
     * Extract the specified environment roles from the list (given in --role).
     *
     * @param string[] $roles
     * @param array<string, Environment> $environments
     *
     * @return array<string, string>
     *   An array of environment roles, keyed by environment ID.
     */
    private function getSpecifiedEnvironmentRoles(array $roles, array $environments): array
    {
        $environmentRoles = [];
        foreach ($roles as $role) {
            if (!str_contains($role, ':')) {
                continue;
            }
            [$id, $role] = explode(':', $role, 2);
            $role = $this->validateEnvironmentRole($role);
            // Match environment IDs by wildcard.
            $matched = Wildcard::select(\array_keys($environments), [$id]);
            if (empty($matched)) {
                throw new InvalidArgumentException('No environment IDs match: ' . $id);
            }
            foreach ($matched as $environmentId) {
                $environmentRoles[$environmentId] = $role;
            }
        }

        return $environmentRoles;
    }

    /**
     * Extract the specified environment type roles from the list (given in --role).
     *
     * @param string[] &$roles
     *   An array of role options (e.g. type:role or environment:role).
     *   The $roles array will be modified to remove the values that were used.
     * @param EnvironmentType[] $environmentTypes
     *
     * @return array<string, string>
     *   An array of environment type roles, keyed by environment type ID.
     */
    private function getSpecifiedTypeRoles(array &$roles, array $environmentTypes): array
    {
        $typeRoles = [];
        $typeIds = array_map(fn(EnvironmentType $type) => $type->id, $environmentTypes);
        foreach ($roles as $key => $role) {
            if (!str_contains($role, ':')) {
                continue;
            }
            [$id, $role] = explode(':', $role, 2);
            $role = $this->validateEnvironmentRole($role);
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

    /**
     * Converts environment-specific roles to environment type roles.
     *
     * This will output messages to stderr about the conversion.
     *
     * @param array<string, string> $specifiedEnvironmentRoles
     * @param array<string, string> $specifiedTypeRoles
     * @param array<string, Environment> $environments
     * @param OutputInterface $stdErr
     *
     * @return array<string, string>|false
     *   A list of environment type roles, keyed by type, or false on failure.
     */
    private function convertEnvironmentRolesToTypeRoles(array $specifiedEnvironmentRoles, array $specifiedTypeRoles, array $environments, OutputInterface $stdErr): false|array
    {
        /** @var array<string, array<string, string>> $byType Roles keyed by environment ID, then keyed by type */
        $byType = [];
        foreach ($specifiedEnvironmentRoles as $id => $role) {
            if (!isset($environments[$id])) {
                throw new \RuntimeException("Failed to find environment for ID: $id");
            }
            $type = $environments[$id]->type;
            $byType[$type][$id] = $role;
        }

        foreach ($byType as $type => $roles) {
            if (count(\array_unique($roles)) > 1) {
                $stdErr->writeln(\sprintf("Conflicting roles were given for environments of type <error>%s</error>:", $type));
                \ksort($roles, SORT_STRING);
                foreach ($roles as $id => $role) {
                    $stdErr->writeln("    <error>$id</error>: $role");
                }
                return false;
            }
            $role = (string) \reset($roles);
            if (isset($specifiedTypeRoles[$type])) {
                if ($specifiedTypeRoles[$type] !== $role) {
                    $stdErr->writeln(sprintf(
                        'The role <error>%s</error> specified on %d environment(s) conflicts with the role <error>%s</error> specified for the environment type <error>%s</error>.',
                        $role,
                        count($roles),
                        $specifiedTypeRoles[$type],
                        $type,
                    ));
                    return false;
                }
                $stdErr->writeln(sprintf(
                    'The role <comment>%s</comment> specified on %d environment(s) will be ignored as it is already specified on their type, <comment>%s</comment>.',
                    $role,
                    count($roles),
                    $type,
                ));
                continue;
            }
            $stdErr->writeln(sprintf(
                'The role <comment>%s</comment> specified on %d environment(s) will actually be applied to all existing and future environments of the same type, <comment>%s</comment>.',
                $role,
                count($roles),
                $type,
            ));
            $specifiedTypeRoles[$type] = $role;
        }
        return $specifiedTypeRoles;
    }

    private function warnProjectAdminConflictingRoles(): void
    {
        $this->stdErr->writeln('<comment>A project admin has administrative access to all environment types.</comment>');
        $this->stdErr->writeln("To set the user's environment type role(s), set their project role to '" . ProjectUserAccess::ROLE_VIEWER . "'.");
    }
}
