<?php
namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Util\Wildcard;
use Platformsh\Client\Model\EnvironmentAccess;
use Platformsh\Client\Model\EnvironmentType;
use Platformsh\Client\Model\Invitation\AlreadyInvitedException;
use Platformsh\Client\Model\Invitation\Permission;
use Platformsh\Client\Model\ProjectAccess;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class UserAddCommand extends CommandBase
{
    /**
     * Backwards compatibility settings.
     *
     * These are used to identify BC code more easily, for future removal.
     *
     * @var bool
     * Whether environment ID-based (rather than type-based) access is supported for backwards compatibility.
     * This will be automatically converted to type-based access, for projects
     * that support environment types.
     */
    const BC_CONVERT_ID_BASED_ACCESS = true;

    protected function configure()
    {
        $this
            ->setName('user:add')
            ->setDescription('Add a user to the project')
            ->addArgument('email', InputArgument::OPTIONAL, "The user's email address");

        $this->addRoleOption();

        if ($this->config()->getWithDefault('api.invitations', false)) {
            $this->addOption('force-invite', null, InputOption::VALUE_NONE, 'Send an invitation, even if one has already been sent');
        }

        $this->addProjectOption();
        $this->addWaitOptions();

        $this->addExample('Add Alice as a project admin', 'alice@example.com -r admin');
        $this->addExample('Add Bob as a viewer on the "production" environment type, and a contributor on "development" environments', 'bob@example.com -r production:v -r development:c');
        $this->addExample('Add Charlie as viewer on "production" and "development"', 'charlie@example.com -r prod%:v -r dev%:v');
    }

    /**
     * Adds the --role (-r) option to the command.
     */
    protected function addRoleOption()
    {
        $this->addOption(
            'role',
            'r',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            "The user's project role ('admin' or 'viewer') or environment type role (e.g. 'staging:contributor' or 'production:viewer')."
            . "\nTo remove a user from an environment type, set the role as 'none'."
            . "\nThe % character can be used as a wildcard for the environment type, e.g. '%:viewer' to give the user the 'viewer' role on all types."
            . "\nThe role can be abbreviated, e.g. 'production:v'."
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $project = $this->getSelectedProject();

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $hasOutput = false;

        $environmentTypes = $this->api()->getEnvironmentTypes($project);

        // Process the --role option.
        $roleInput = ArrayArgument::getOption($input, 'role');
        $specifiedProjectRole = $this->getSpecifiedProjectRole($roleInput);
        if (self::BC_CONVERT_ID_BASED_ACCESS) {
            $specifiedTypeRoles = $this->getSpecifiedTypeRoles($roleInput, $environmentTypes);
            if (!empty($roleInput)) {
                $specifiedEnvironmentRoles = $this->getSpecifiedEnvironmentRoles($roleInput, $this->api()->getEnvironments($project));
            }
        } else {
            $specifiedTypeRoles = $this->getSpecifiedTypeRoles($roleInput, $environmentTypes, false);
        }
        if ($specifiedProjectRole === ProjectAccess::ROLE_ADMIN && (!empty($specifiedTypeRoles) || !empty($specifiedEnvironmentRoles))) {
            $this->warnProjectAdminConflictingRoles();
            return 1;
        }

        if (self::BC_CONVERT_ID_BASED_ACCESS && !empty($specifiedEnvironmentRoles)) {
            $this->stdErr->writeln('<fg=yellow;options=bold>Warning:</>');
            $this->stdErr->writeln('<fg=yellow>Access control is now based on environment types, not individual environments.</>');
            $this->stdErr->writeln('<fg=yellow>Please use the environment type to specify roles.</>');
            // In interactive use, error out. In non-interactive use, warn but continue (for backwards compatibility).
            if ($input->isInteractive()) {
                // Try to show an example of how to use type-based roles.
                $environments = $this->api()->getEnvironments($project);
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
            $environments = $this->api()->getEnvironments($project, true);
            $converted = $this->convertEnvironmentRolesToTypeRoles($specifiedEnvironmentRoles, $specifiedTypeRoles, $environments, $this->stdErr);
            if ($converted === false) {
                return 1;
            }
            $specifiedTypeRoles = $converted;
            unset($specifiedEnvironmentRoles);
            $this->stdErr->writeln('');
        }

        // Process the [email] argument.
        $email = $input->getArgument('email');
        if (!$email) {
            $update = stripos($input->getFirstArgument(), ':u');
            if ($update && $input->isInteractive()) {
                $choices = [];
                foreach ($this->api()->getProjectAccesses($project) as $access) {
                    $account = $this->api()->getAccount($access);
                    $choices[$account['email']] = $this->getUserLabel($access);
                }
                $email = $questionHelper->choose($choices, 'Enter a number to choose a user to update:');
            } else {
                $question = new Question("Enter the user's email address: ");
                $question->setValidator(function ($answer) {
                    return $this->validateEmail($answer);
                });
                $question->setMaxAttempts(5);
                $email = $questionHelper->ask($input, $this->stdErr, $question);
                $hasOutput = true;
            }
        }
        $this->validateEmail($email);

        // Check the user's existing role on the project.
        $existingProjectAccess = $this->api()->loadProjectAccessByEmail($project, $email);
        $existingTypeRoles = [];
        if ($existingProjectAccess) {
            // Exit if the user is the owner already.
            if ($existingProjectAccess->id === $project->owner) {
                if ($hasOutput) {
                    $this->stdErr->writeln('');
                }

                $this->stdErr->writeln(sprintf('The user %s is the owner of %s.', $this->getUserLabel($existingProjectAccess), $this->api()->getProjectLabel($project)));
                if ($specifiedProjectRole || $specifiedTypeRoles) {
                    $this->stdErr->writeln('');
                    $this->stdErr->writeln("<comment>The project owner's role(s) cannot be changed.</comment>");

                    return 1;
                }

                return 0;
            }

            // Check the user's existing role(s) on the project's environments and types.
            $existingTypeRoles = $this->getTypeRoles($existingProjectAccess, $environmentTypes);
        }

        // If the user already exists, print a summary of their roles on the
        // project and environments.
        if ($existingProjectAccess) {
            if ($hasOutput) {
                $this->stdErr->writeln('');
            }

            $this->stdErr->writeln(sprintf('Current role(s) of <info>%s</info> on %s:', $this->getUserLabel($existingProjectAccess), $this->api()->getProjectLabel($project)));
            $this->stdErr->writeln(sprintf('  Project role: <info>%s</info>', $existingProjectAccess->role));
            if ($existingProjectAccess->role !== ProjectAccess::ROLE_ADMIN) {
                foreach ($environmentTypes as $type) {
                    $role = isset($existingTypeRoles[$type->id]) ? $existingTypeRoles[$type->id] : '[none]';
                    $this->stdErr->writeln(sprintf('    Role on environment type <info>%s</info>: %s', $type->id, $role));
                }
            }
            $hasOutput = true;
        }

        // Resolve or merge the project role.
        $desiredProjectRole = $specifiedProjectRole ?: ($existingProjectAccess ? $existingProjectAccess->role : ProjectAccess::ROLE_VIEWER);
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
            && $desiredProjectRole !== ProjectAccess::ROLE_ADMIN
            && !$specifiedTypeRoles;
        // Resolve or merge the environment type role(s).
        if ($desiredProjectRole !== ProjectAccess::ROLE_ADMIN) {
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
        if ($existingProjectAccess) {
            if ($existingProjectAccess->role !== $desiredProjectRole) {
                $changesText[] = sprintf('Project role: <error>%s</error> -> <info>%s</info>', $existingProjectAccess->role, $desiredProjectRole);
            }
        } else {
            $changesText[] = sprintf('Project role: <info>%s</info>', $desiredProjectRole);
        }
        $typeChanges = [];
        if ($desiredProjectRole !== ProjectAccess::ROLE_ADMIN) {
            if ($desiredTypeRoles) {
                foreach ($environmentTypes as $environmentType) {
                    $id = $environmentType->id;
                    $new = isset($desiredTypeRoles[$id]) ? $desiredTypeRoles[$id] : 'none';
                    if ($existingTypeRoles) {
                        $existing = isset($existingTypeRoles[$id]) ? $existingTypeRoles[$id] : 'none';
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
        $desiredTypeRoles = array_filter($desiredTypeRoles, function ($role) {
            return $role !== 'none';
        });

        // Add a new line if there has already been output.
        if ($hasOutput) {
            $this->stdErr->writeln('');
        }

        // Require project non-admins to be added to at least one environment.
        if ($desiredProjectRole === ProjectAccess::ROLE_VIEWER && !$desiredTypeRoles) {
            $this->stdErr->writeln('<error>No environment types selected.</error>');
            $this->stdErr->writeln('A non-admin user must be added to at least one environment type.');

            if ($existingProjectAccess) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(sprintf(
                    'To delete the user, run: <info>%s user:delete %s</info>',
                    $this->config()->get('application.executable'),
                    $this->api()->getAccount($existingProjectAccess)['email']
                ));
            }

            return 1;
        }

        // Prevent changing environment access for project admins.
        if ($desiredProjectRole === ProjectAccess::ROLE_ADMIN && $specifiedTypeRoles) {
            $this->warnProjectAdminConflictingRoles();
            return 1;
        }

        // Print a summary of the changes that are about to be made.
        if ($existingProjectAccess) {
            $this->stdErr->writeln('Summary of changes:');
        } else {
            $this->stdErr->writeln(sprintf('Adding the user <info>%s</info> to %s:', $email, $this->api()->getProjectLabel($project)));
        }
        foreach ($changesText as $change) {
            $this->stdErr->writeln('  ' . $change);
        }
        $this->stdErr->writeln('');

        // Ask for confirmation.
        if ($existingProjectAccess) {
            if (!$questionHelper->confirm('Are you sure you want to make these change(s)?')) {
                return 1;
            }
        } else {
            $this->stdErr->writeln('<comment>Adding users can result in additional charges.</comment>');
            $this->stdErr->writeln('');
            if (!$questionHelper->confirm('Are you sure you want to add this user?')) {
                return 1;
            }
        }
        $this->stdErr->writeln('');

        // If the user does not already exist on the project, then use the Invitations API.
        if (!$existingProjectAccess && $this->config()->getWithDefault('api.invitations', false)) {
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
                if ($questionHelper->confirm('Do you want to send this invitation anyway?')) {
                    $project->inviteUserByEmail($email, $desiredProjectRole, [], true, $permissions);
                    $this->stdErr->writeln('');
                    $this->stdErr->writeln(sprintf('A new invitation has been sent to <info>%s</info>', $email));
                }
            }

            return 0;
        }

        // Make the desired changes at the project level.
        if (!$existingProjectAccess) {
            $this->stdErr->writeln("Adding the user to the project");
            $result = $project->addUser($email, $desiredProjectRole);
            $activities = $result->getActivities();
            /** @var ProjectAccess $projectAccess */
            $projectAccess = $result->getEntity();
            $userId = $projectAccess->id;
        } elseif ($existingProjectAccess->role !== $desiredProjectRole) {
            $this->stdErr->writeln("Setting the user's project role to: $desiredProjectRole");
            $result = $existingProjectAccess->update(['role' => $desiredProjectRole]);
            $activities = $result->getActivities();
            $userId = $existingProjectAccess->id;
        } else {
            $userId = $existingProjectAccess->id;
            $activities = [];
        }

        // Make the desired changes at the environment type level.
        if ($desiredProjectRole !== ProjectAccess::ROLE_ADMIN) {
            foreach ($typeChanges as $typeId => $role) {
                $type = $project->getEnvironmentType($typeId);
                if (!$type) {
                    $this->stdErr->writeln('Environment type not found: <comment>' . $typeId . '</comment>');
                    continue;
                }
                $access = $type->getUser($userId);
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
                    $result = $type->addUser($userId, $role);
                }
                $activities = array_merge($activities, $result->getActivities());
            }
        }

        // Wait for activities to complete.
        if (!$activities) {
            $this->redeployWarning();
        } elseif ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            if (!$activityMonitor->waitMultiple($activities, $project)) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private function validateProjectRole($value)
    {
        return $this->matchRole($value, ProjectAccess::$roles);
    }

    /**
     * @param string $value
     *
     * @return string
     */
    private function validateEnvironmentRole($value)
    {
        return $this->matchRole($value, array_merge(EnvironmentAccess::$roles, ['none']));
    }

    /**
     * Validate an email address.
     *
     * @param string $value
     *
     * @throws InvalidArgumentException
     *
     * @return string
     */
    private function validateEmail($value)
    {
        if (empty($value)) {
            throw new InvalidArgumentException('An email address is required.');
        }
        if (!$filtered = filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email address: ' . $value);
        }

        return $filtered;
    }

    /**
     * Complete a role name based on an array of allowed roles.
     *
     * @param string   $input
     * @param string[] $roles
     *
     * @return string
     */
    private function matchRole($input, array $roles)
    {
        foreach ($roles as $role) {
            if (strpos($role, strtolower($input)) === 0) {
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
    private function describeRoles(array $roles)
    {
        $withInitials = array_map(function ($role) {
            return sprintf('%s (%s)', $role, substr($role, 0, 1));
        }, $roles);
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
    private function describeRoleInput(array $roles)
    {
        return '[' . implode('/', array_map(function ($role) {
            return substr($role, 0, 1);
        }, $roles)) . ']';
    }

    /**
     * Return a label describing a user.
     *
     * @param ProjectAccess $access
     *
     * @return string
     */
    private function getUserLabel(ProjectAccess $access)
    {
        $account = $this->api()->getAccount($access);

        return sprintf('<info>%s</info> (%s)', $account['display_name'], $account['email']);
    }

    /**
     * Show the form for entering the project role.
     *
     * @param string                                          $defaultRole
     * @param InputInterface $input
     *
     * @return string
     */
    private function showProjectRoleForm($defaultRole, InputInterface $input)
    {
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $this->stdErr->writeln("The user's project role can be " . $this->describeRoles(ProjectAccess::$roles) . '.');
        $this->stdErr->writeln('');
        $question = new Question(
            sprintf('Project role (default: %s) <question>%s</question>: ', $defaultRole, $this->describeRoleInput(ProjectAccess::$roles)),
            $defaultRole
        );
        $question->setValidator(function ($answer) {
            return $this->validateProjectRole($answer);
        });
        $question->setMaxAttempts(5);
        $question->setAutocompleterValues(ProjectAccess::$roles);

        return $questionHelper->ask($input, $this->stdErr, $question);
    }

    /**
     * Load the user's roles on the project's environment types.
     *
     * @param ProjectAccess $projectAccess
     * @param EnvironmentType[] $environmentTypes
     *
     * @return array
     */
    private function getTypeRoles(ProjectAccess $projectAccess, array $environmentTypes)
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
     * @param array $defaultTypeRoles
     * @param EnvironmentType[] $environmentTypes
     * @param InputInterface $input
     *
     * @return array
     *   The environment type roles (keyed by type ID) including the user's
     *   answers.
     */
    private function showTypeRolesForm(array $defaultTypeRoles, array $environmentTypes, InputInterface $input)
    {
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $desiredTypeRoles = [];
        $validRoles = array_merge(EnvironmentAccess::$roles, ['none']);
        $this->stdErr->writeln("The user's environment type role(s) can be " . $this->describeRoles($validRoles) . '.');
        $initials = $this->describeRoleInput($validRoles);
        $this->stdErr->writeln('');
        foreach ($environmentTypes as $environmentType) {
            $id = $environmentType->id;
            $default = isset($defaultTypeRoles[$id]) ? $defaultTypeRoles[$id] : 'none';
            $question = new Question(
                sprintf('Role on type <info>%s</info> (default: %s) <question>%s</question>: ', $id, $default, $initials),
                $default
            );
            $question->setValidator(function ($answer) {
                if ($answer === 'q' || $answer === 'quit') {
                    return $answer;
                }

                return $this->validateEnvironmentRole($answer);
            });
            $question->setAutocompleterValues(array_merge($validRoles, ['quit']));
            $question->setMaxAttempts(5);
            $answer = $questionHelper->ask($input, $this->stdErr, $question);
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
     * @param array &$roles
     *
     * @return string|null
     *   The project role, or null if none is specified.
     */
    private function getSpecifiedProjectRole(array &$roles)
    {
        foreach ($roles as $key => $role) {
            if (strpos($role, ':') === false) {
                unset($roles[$key]);
                return $this->validateProjectRole($role);
            }
        }

        return null;
    }

    /**
     * Extract the specified environment roles from the list (given in --role).
     *
     * @param string[] $roles
     * @param array<string, \Platformsh\Client\Model\Environment> $environments
     *
     * @return array<string, string>
     *   An array of environment roles, keyed by environment ID.
     */
    private function getSpecifiedEnvironmentRoles(array $roles, array $environments)
    {
        $environmentRoles = [];
        foreach ($roles as $role) {
            if (strpos($role, ':') === false) {
                continue;
            }
            list($id, $role) = explode(':', $role, 2);
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
     * @param bool $ignoreErrors
     *
     * @return array<string, string>
     *   An array of environment type roles, keyed by environment type ID.
     */
    private function getSpecifiedTypeRoles(array &$roles, array $environmentTypes, $ignoreErrors = true)
    {
        $typeRoles = [];
        $typeIds = array_map(function (EnvironmentType $type) { return $type->id; }, $environmentTypes);
        foreach ($roles as $key => $role) {
            if (strpos($role, ':') === false) {
                continue;
            }
            list($id, $role) = explode(':', $role, 2);
            $role = $this->validateEnvironmentRole($role);
            // Match type IDs by wildcard.
            // Error for non-wildcard matches.
            $matched = Wildcard::select($typeIds, [$id]);
            if (empty($matched)) {
                if (!$ignoreErrors) {
                    throw new InvalidArgumentException('No environment type IDs match: ' . $id);
                }
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
     * @param array<string, \Platformsh\Client\Model\Environment> $environments
     * @param OutputInterface $stdErr
     *
     * @return array<string, string>|false
     *   A list of environment type roles, keyed by type, or false on failure.
     */
    private function convertEnvironmentRolesToTypeRoles(array $specifiedEnvironmentRoles, array $specifiedTypeRoles, array $environments, OutputInterface $stdErr)
    {
        /** @var array<string, array<string, string>> $byType Roles keyed by environment ID, then keyed by type */
        $byType = [];
        foreach ($specifiedEnvironmentRoles as $id => $role) {
            if (!isset($environments[$id])) {
                throw new \RuntimeException("Failed to find environment for ID: $id");
            }
            $type = $environments[$id]->getProperty('type');
            $byType[$type][$id] = $role;
        }

        foreach ($byType as $type => $roles) {
            if (count(\array_unique($roles, SORT_STRING)) > 1) {
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
                        $type
                    ));
                    return false;
                }
                $stdErr->writeln(sprintf(
                    'The role <comment>%s</comment> specified on %d environment(s) will be ignored as it is already specified on their type, <comment>%s</comment>.',
                    $role,
                    count($roles),
                    $type
                ));
                continue;
            }
            $stdErr->writeln(sprintf(
                'The role <comment>%s</comment> specified on %d environment(s) will actually be applied to all existing and future environments of the same type, <comment>%s</comment>.',
                $role,
                count($roles),
                $type
            ));
            $specifiedTypeRoles[$type] = $role;
        }
        return $specifiedTypeRoles;
    }

    private function warnProjectAdminConflictingRoles()
    {
        $this->stdErr->writeln('<comment>A project admin has administrative access to all environment types.</comment>');
        $this->stdErr->writeln("To set the user's environment type role(s), set their project role to '" . ProjectAccess::ROLE_VIEWER . "'.");
    }
}
