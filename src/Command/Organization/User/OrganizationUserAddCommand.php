<?php

namespace Platformsh\Cli\Command\Organization\User;

use Platformsh\Cli\Command\Organization\OrganizationCommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class OrganizationUserAddCommand extends OrganizationCommandBase
{
    protected static $defaultName = 'organization:user:add';
    protected static $defaultDescription = 'Invite a user to an organization';

    private $api;
    private $questionHelper;
    private $selector;

    public function __construct(Config $config, Api $api, QuestionHelper $questionHelper, Selector $selector)
    {
        $this->api = $api;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        parent::__construct($config);
    }

    protected function configure()
    {
        $this->setName('organization:user:add');
        $this->selector->addOrganizationOptions($this->getDefinition());
        $this->addArgument('email', InputArgument::OPTIONAL, 'The email address of the user')
            ->addOption('permission', null, InputOption::VALUE_REQUIRED|InputOption::VALUE_IS_ARRAY, 'Permission(s) for the user on the organization');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $organization = $this->selector->selectOrganization($input, 'create-member');

        $email = $input->getArgument('email');
        if ($email) {
            $email = $this->validateEmail($email);
        } elseif (!$input->isInteractive()) {
            $this->stdErr->writeln('A user email address is required.');
            return 1;
        } else {
            $question = new Question("Enter the user's email address: ");
            $question->setValidator(function ($answer) {
                return $this->validateEmail($answer);
            });
            $question->setMaxAttempts(5);
            $email = $this->questionHelper->ask($input, $this->stdErr, $question);
        }

        $permissions = $input->getOption('permission');
        if (count($permissions) === 1) {
            $permissions = \preg_split('/[,\s]+/', $permissions[0]) ?: [];
        }

        $members = $organization->getMembers();
        foreach ($members as $member) {
            if ($info = $member->getUserInfo()) {
                if ($info->email === $email) {
                    $this->stdErr->writeln(\sprintf('The user <info>%s</info> already exists on the organization %s', $email, $this->api->getOrganizationLabel($organization)));
                    if ($member->permissions != $permissions && !empty($permissions) && !$member->owner) {
                        $this->stdErr->writeln('');
                        $this->stdErr->writeln(\sprintf(
                            "To change the user's permissions, run:\n<comment>%s</comment>",
                            $this->otherCommandExample($input, 'org:user:update', \escapeshellarg($email) . ' --permission ' . \escapeshellarg(implode(', ', $permissions)))
                        ));
                        return 1;
                    }
                    return 0;
                }
            }
        }

        if (!$this->questionHelper->confirm(\sprintf('Are you sure you want to invite %s to the organization %s?', $email, $this->api->getOrganizationLabel($organization)))) {
            return 1;
        }

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

    /**
     * Validates an email address.
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
}
