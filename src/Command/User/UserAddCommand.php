<?php
namespace Platformsh\Cli\Command\User;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\EnvironmentAccess;
use Platformsh\Client\Model\ProjectAccess;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class UserAddCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('user:add')
            ->setAliases(['user:update'])
            ->setDescription('Add a user to the project, or set their role(s)')
            ->addArgument('email', InputArgument::OPTIONAL, "The user's email address")
            ->addOption('role', 'r', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, "The user's role: 'admin' or 'viewer', or environment-specific role e.g. 'master:contributor' or 'stage:viewer'");
        $this->addProjectOption();
        $this->addNoWaitOption();
        $this->addExample('Add Alice as a project admin', 'alice@example.com -r admin');
        $this->addExample('Make Bob an admin on the "develop" and "stage" environments', 'bob@example.com -r develop:a,stage:a');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $project = $this->getSelectedProject();
        $projectLabel = $this->api()->getProjectLabel($project);

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $email = $input->getArgument('email');
        if (!$email) {
            $update = stripos($input->getFirstArgument(), ':u');
            if ($update && $input->isInteractive()) {
                $choices = [];
                foreach ($project->getUsers() as $access) {
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
            }
            $this->stdErr->writeln('');
        }
        $this->validateEmail($email);

        // Expand the --role option, allowing for comma-separated values.
        $roleInput = $input->getOption('role');
        if (count($roleInput) === 1) {
            $roleInput = preg_split('/[\s,]+/', reset($roleInput));
        }

        // Extract the project and environment roles from the --role option.
        $specifiedProjectRole = '';
        $specifiedEnvironmentRoles = [];
        foreach ($roleInput as $role) {
            if (strpos($role, ':') === false) {
                $specifiedProjectRole = $this->validateProjectRole($role);
            } else {
                list($id, $role) = explode(':', $role, 2);
                $specifiedEnvironmentRoles[$id] = $this->validateEnvironmentRole($role);
            }
        }

        // Validate the list of environment roles.
        if (!empty($specifiedEnvironmentRoles)) {
            if ($missing = array_diff(array_keys($specifiedEnvironmentRoles), array_keys($this->api()->getEnvironments($project)))) {
                $this->stdErr->writeln('Environment(s) not found: <error>' . implode(', ', $missing) . '</error>');

                return 1;
            }
        }

        // Check the user's existing role on the project.
        $existingProjectAccess = $this->api()->loadProjectAccessByEmail($project, $email);
        $existingEnvironmentRoles = [];
        if ($existingProjectAccess) {
            // Exit if the user is the owner already.
            if ($existingProjectAccess->id === $project->owner) {
                $this->stdErr->writeln(sprintf('The user %s is the owner of %s.', $this->getUserLabel($existingProjectAccess), $projectLabel));
                if ($specifiedProjectRole || !empty($specifiedEnvironmentRoles)) {
                    $this->stdErr->writeln('');
                    $this->stdErr->writeln("<comment>The project owner's role(s) cannot be changed.</comment>");

                    return 1;
                }

                return 0;
            }

            // Check the user's existing role(s) on the project's environments.
            if ($existingProjectAccess->role !== ProjectAccess::ROLE_ADMIN) {
                foreach ($this->api()->getEnvironments($project) as $environment) {
                    $environmentAccess = $environment->getUser($existingProjectAccess->id);
                    if (!$environmentAccess) {
                        continue;
                    }
                    $existingEnvironmentRoles[$environment->id] = $environmentAccess->role;
                }
            }
        }

        // If the user already exists, print a summary of their roles on the
        // project and environments.
        if ($existingProjectAccess) {
            $this->stdErr->writeln(sprintf('Current role(s) of <info>%s</info> on %s:', $this->getUserLabel($existingProjectAccess), $projectLabel));
            $this->stdErr->writeln(sprintf('  Project role: <info>%s</info>', $existingProjectAccess->role));
            foreach ($existingEnvironmentRoles as $id => $role) {
                $this->stdErr->writeln(sprintf('    Role on <info>%s</info>: %s', $id, $role));
            }
        }

        // Resolve the default project role. Provide an interactive form if
        // there is no --role option.
        $specifiedProjectRole = $specifiedProjectRole ?: ($existingProjectAccess ? $existingProjectAccess->role : ProjectAccess::ROLE_VIEWER);
        $provideProjectForm = empty($roleInput) && $input->isInteractive();
        if ($provideProjectForm) {
            if ($existingProjectAccess) {
                $this->stdErr->writeln('');
            }
            $this->stdErr->writeln("The user's project role can be " . $this->describeRoles(ProjectAccess::$roles) . '.');
            $question = new Question(
                sprintf('Project role (default: %s) <question>%s</question>: ', $specifiedProjectRole, $this->describeRoleInput(ProjectAccess::$roles)),
                $specifiedProjectRole
            );
            $question->setValidator(function ($answer) {
                return $this->validateProjectRole($answer);
            });
            $question->setMaxAttempts(5);
            $question->setAutocompleterValues(ProjectAccess::$roles);
            $specifiedProjectRole = $questionHelper->ask($input, $this->stdErr, $question);
        }

        // If the user isn't (going to be) a project admin, then resolve the
        // role for each environment.
        if ($specifiedProjectRole !== ProjectAccess::ROLE_ADMIN) {
            foreach ($this->api()->getEnvironments($project) as $id => $environment) {
                if (isset($existingEnvironmentRoles[$id]) && !isset($specifiedEnvironmentRoles[$id])) {
                    $specifiedEnvironmentRoles[$id] = $existingEnvironmentRoles[$id];
                }
            }
        }

        // Provide a form for selecting environment roles.
        $provideEnvironmentForm = $input->isInteractive()
            && $specifiedProjectRole !== ProjectAccess::ROLE_ADMIN
            && empty($specifiedEnvironmentRoles);
        if ($provideEnvironmentForm) {
            if ($existingProjectAccess || $provideProjectForm) {
                $this->stdErr->writeln('');
            }
            $environmentRoles = array_merge(EnvironmentAccess::$roles, ['none']);
            $this->stdErr->writeln("The user's environment role(s) can be " . $this->describeRoles($environmentRoles) . '.');
            $this->stdErr->writeln('');
            foreach (array_keys($this->api()->getEnvironments($project)) as $id) {
                $default = isset($specifiedEnvironmentRoles[$id]) ? $specifiedEnvironmentRoles[$id] : 'none';
                $question = new Question(
                    sprintf('Role on <info>%s</info> (default: %s) <question>%s</question>: ', $id, $default, $this->describeRoleInput($environmentRoles)),
                    $default
                );
                $question->setValidator(function ($answer) use ($environmentRoles) {
                    if ($answer === 'q' || $answer === 'quit') {
                        return $answer;
                    }

                    return $this->validateEnvironmentRole($answer);
                });
                $question->setAutocompleterValues(array_merge($environmentRoles, ['quit']));
                $question->setMaxAttempts(5);
                $answer = $questionHelper->ask($input, $this->stdErr, $question);
                if ($answer === 'q' || $answer === 'quit') {
                    break;
                } else {
                    $specifiedEnvironmentRoles[$id] = $answer;
                }
            }
        }

        // Check that there is going to be access to at least one environment.
        $specifiedEnvironmentRoles = array_filter($specifiedEnvironmentRoles, function ($role) {
            return $role !== 'none';
        });
        $notEnoughEnvironments = $specifiedProjectRole === ProjectAccess::ROLE_VIEWER && empty($specifiedEnvironmentRoles);

        // Build a list of the changes that are going to be made.
        $changes = [];
        if ($existingProjectAccess) {
            if ($existingProjectAccess->role !== $specifiedProjectRole) {
                $changes[] = sprintf('Project role: <error>%s</error> -> <info>%s</info>', $existingProjectAccess->role, $specifiedProjectRole);
            }
        } elseif (!$notEnoughEnvironments) {
            $changes[] = sprintf('Project role: <info>%s</info>', $specifiedProjectRole);
        }
        if ($specifiedProjectRole !== ProjectAccess::ROLE_ADMIN) {
            foreach ($this->api()->getEnvironments($project) as $id => $environment) {
                $new = isset($specifiedEnvironmentRoles[$id]) ? $specifiedEnvironmentRoles[$id] : 'none';
                if (!empty($existingEnvironmentRoles)) {
                    $existing = isset($existingEnvironmentRoles[$id]) ? $existingEnvironmentRoles[$id] : 'none';
                    if ($existing !== $new) {
                        $changes[] = sprintf('Role on <info>%s</info>: <error>%s</error> -> <info>%s</info>', $id, $existing, $new);
                    }
                } elseif ($new !== 'none') {
                    $changes[] = sprintf('Role on <info>%s</info>: <info>%s</info>', $id, $new);
                }
            }
        }

        // Require project non-admins to be added to at least one environment.
        if ($notEnoughEnvironments) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('<comment>A non-admin user must be added to at least one environment.</comment>');

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
        if ($specifiedProjectRole === ProjectAccess::ROLE_ADMIN && !empty($specifiedEnvironmentRoles)) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('<comment>A project admin has administrative access to all environments.</comment>');
            $this->stdErr->writeln("To set the user's environment role(s), set their project role to '" . ProjectAccess::ROLE_VIEWER . "'.");

            return 1;
        }

        // Exit early if there are no changes to make.
        if (empty($changes)) {
            if ($provideProjectForm || $provideEnvironmentForm) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('There are no changes to make.');
            }

            return 0;
        }

        // Add a new line if there has already been output.
        if ($existingProjectAccess || $provideProjectForm || $provideEnvironmentForm) {
            $this->stdErr->writeln('');
        }

        // Print a summary of the changes that are about to be made.
        if ($existingProjectAccess) {
            $this->stdErr->writeln('Summary of changes:');
        } else {
            $this->stdErr->writeln(sprintf('Adding the user <info>%s</info> to %s:', $email, $this->api()->getProjectLabel($project)));
        }
        foreach ($changes as $change) {
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

        // Make the required modifications on the project level: add the user,
        // change their role, or do nothing.
        if (!$existingProjectAccess) {
            $this->stdErr->writeln("Adding the user to the project");
            $result = $project->addUser($email, $specifiedProjectRole);
            $activities = $result->getActivities();
            /** @var ProjectAccess $projectAccess */
            $projectAccess = $result->getEntity();
            $uuid = $projectAccess->id;
        } elseif ($existingProjectAccess->role !== $specifiedProjectRole) {
            $this->stdErr->writeln("Setting the user's project role to: $specifiedProjectRole");
            $result = $existingProjectAccess->update(['role' => $specifiedProjectRole]);
            $activities = $result->getActivities();
            $uuid = $existingProjectAccess->id;
        } else {
            $uuid = $existingProjectAccess->id;
            $activities = [];
        }

        // Make the desired changes at the environment level.
        if ($specifiedProjectRole !== ProjectAccess::ROLE_ADMIN) {
            foreach ($this->api()->getEnvironments($project) as $environmentId => $environment) {
                $role = isset($specifiedEnvironmentRoles[$environmentId]) ? $specifiedEnvironmentRoles[$environmentId] : 'none';
                $access = $environment->getUser($uuid);
                if ($role === 'none') {
                    if ($access) {
                        $this->stdErr->writeln("Removing the user from the environment <info>$environmentId</info>");
                        $result = $access->delete();
                    } else {
                        continue;
                    }
                } else {
                    if ($access) {
                        if ($access->role === $role) {
                            continue;
                        }
                        $this->stdErr->writeln("Setting the user's role on the environment <info>$environmentId</info> to: $role");
                        $result = $access->update(['role' => $role]);
                    } else {
                        $this->stdErr->writeln("Adding the user to the environment: <info>$environmentId</info>");
                        $result = $environment->addUser($uuid, $role);
                    }
                }
                $activities = array_merge($activities, $result->getActivities());
            }
        }

        // Wait for activities to complete.
        if (!$input->getOption('no-wait')) {
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
     * @throws \Symfony\Component\Console\Exception\InvalidArgumentException
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
            return sprintf("%s (%s)", $role, substr($role, 0, 1));
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
     * @param \Platformsh\Client\Model\ProjectAccess $access
     *
     * @return string
     */
    private function getUserLabel(ProjectAccess $access)
    {
        $account = $this->api()->getAccount($access);

        return sprintf('<info>%s</info> (%s)', $account['display_name'], $account['email']);
    }
}
