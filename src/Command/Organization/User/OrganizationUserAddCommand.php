<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Organization\User;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'organization:user:add', description: 'Invite a user to an organization')]
class OrganizationUserAddCommand extends OrganizationUserCommandBase
{
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly QuestionHelper $questionHelper, protected readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->selector->addOrganizationOptions($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->addArgument('email', InputArgument::OPTIONAL, 'The email address of the user')
            ->addPermissionOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organization = $this->selector->selectOrganization($input, 'create-member');

        $update = get_called_class() === OrganizationUserUpdateCommand::class;
        if ($update) {
            $email = $input->getArgument('email');
            if (!empty($email)) {
                $existingMember = $this->api->loadMemberByEmail($organization, $email);
                if (!$existingMember) {
                    $this->stdErr->writeln(sprintf('The user <error>%s</error> was not found in the organization %s', $email, $this->api->getOrganizationLabel($organization, 'comment')));
                    return 1;
                }
            } elseif (!$input->isInteractive()) {
                $this->stdErr->writeln('You must specify the email address of a user to update (in non-interactive mode).');
                return 1;
            } else {
                $existingMember = $this->chooseMember($organization);
            }
        } else {
            $existingMember = null;
            $email = $input->getArgument('email');
            if ($email) {
                $email = $this->validateEmail($email);
            } elseif (!$input->isInteractive()) {
                $this->stdErr->writeln('A user email address is required.');
                return 1;
            } else {
                $email = $this->questionHelper->askInput('Enter the email address of a user to add', null, [], fn($answer) => $this->validateEmail($answer));
                $this->stdErr->writeln('');
            }
        }

        if (!$update && $this->api->loadMemberByEmail($organization, $email)) {
            $this->stdErr->writeln(\sprintf('The user <comment>%s</comment> already exists on the organization %s', $email, $this->api->getOrganizationLabel($organization, 'comment')));
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf('To update the user, run: <comment>%s org:user:update %s</comment>', $this->config->getStr('application.executable'), OsUtil::escapeShellArg($email)));
            return 1;
        }

        $permissions = ArrayArgument::getOption($input, 'permission');
        if (empty($permissions)) {
            $this->stdErr->writeln('A user may have any of the following permissions: ' . $this->listPermissions() . '.');
            if ($existingMember) {
                $this->stdErr->writeln(sprintf('The user <info>%s</info> currently has the following permissions: %s.', $this->memberLabel($existingMember), $this->listPermissions($existingMember->permissions)));
                $this->stdErr->writeln('');
                $questionText = 'Enter a list of permissions to replace this with (separated by commas)';
            } else {
                $questionText = 'Optionally, enter a list of permissions to add (separated by commas)';
            }
            $response = $this->questionHelper->askInput($questionText, null, [], function ($value) {
                foreach (ArrayArgument::split([$value]) as $permission) {
                    if (!\in_array($permission, self::$allPermissions)) {
                        throw new InvalidArgumentException('Unrecognized permission: ' . $permission);
                    }
                }
                return $value;
            });
            $permissions = ArrayArgument::split([$response]);
            $this->stdErr->writeln('');
        }

        if ($update) {
            if ($existingMember->permissions == $permissions) {
                $this->stdErr->writeln(\sprintf("The user's permissions are already set to: %s", $this->listPermissions($permissions)));
                return 0;
            }

            if ($existingMember->owner) {
                $this->stdErr->writeln('The user is the owner of the organization, so does not need permissions.');
                return 1;
            }

            $this->stdErr->writeln(\sprintf('Updating the user <info>%s</info> on the organization %s', $this->memberLabel($existingMember), $this->api->getOrganizationLabel($organization)));
            $this->stdErr->writeln('');

            $this->stdErr->writeln('Summary of changes:');

            $this->stdErr->writeln('  Permissions:');
            $same = \array_intersect($existingMember->permissions, $permissions);
            foreach ($same as $permission) {
                $this->stdErr->writeln('      ' . $permission);
            }
            $remove = \array_diff($existingMember->permissions, $permissions);
            foreach ($remove as $permission) {
                $this->stdErr->writeln('    <fg=red>- ' . $permission . '</>');
            }
            $add = \array_diff($permissions, $existingMember->permissions);
            foreach ($add as $permission) {
                $this->stdErr->writeln('    <fg=green>+ ' . $permission . '</>');
            }

            $this->stdErr->writeln('');

            if (!$this->questionHelper->confirm('Are you sure you want to make these changes?')) {
                return 1;
            }

            $result = $existingMember->update(['permissions' => $permissions]);
            $new = $result->getProperty('permissions', false) ?: [];

            $this->stdErr->writeln(\sprintf("The user's permissions are now: %s", $this->listPermissions($new)));
        } elseif (!$this->questionHelper->confirm(\sprintf('Are you sure you want to invite <info>%s</info> to the organization %s?', $email, $this->api->getOrganizationLabel($organization)))) {
            return 1;
        } else {
            $invitation = $organization->inviteMemberByEmail($email, $permissions);

            switch ($invitation->state) {
                case 'accepted':
                    $this->stdErr->writeln('The user has been successfully added to the organization.');
                    return 0;
                case 'cancelled':
                    $this->stdErr->writeln(\sprintf('The invitation <comment>%s</comment> was cancelled.', $invitation->id));
                    return 1;
                case 'error':
                    $this->stdErr->writeln(\sprintf('The invitation <error>%s</error> errored.', $invitation->id));
                    return 1;
                default:
                    $this->stdErr->writeln('The user has been successfully invited to the organization.');
                    return 0;
            }
        }
        return 0;
    }

    /**
     * Validates an email address.
     *
     * @param string|null $value
     *
     * @throws InvalidArgumentException
     *
     * @return string
     */
    private function validateEmail(?string $value): string
    {
        if (empty($value)) {
            throw new InvalidArgumentException('An email address is required.');
        }
        if (!$filtered = filter_var($value, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email address: ' . $value);
        }

        return $filtered;
    }
}
