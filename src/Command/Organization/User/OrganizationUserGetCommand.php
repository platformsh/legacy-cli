<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Organization\User;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Command\Organization\OrganizationCommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Client\Model\Ref\UserRef;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'organization:user:get', description: 'View an organization user')]
class OrganizationUserGetCommand extends OrganizationCommandBase
{
    public function __construct(private readonly Api $api, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->selector->addOrganizationOptions($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->addArgument('email', InputArgument::OPTIONAL, 'The email address of the user')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'A property to display');
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organization = $this->selector->selectOrganization($input, 'members');

        if (!$organization->hasLink('members')) {
            $this->stdErr->writeln('You do not have permission to view users in the organization ' . $this->api->getOrganizationLabel($organization, 'comment') . '.');
            return 1;
        }

        $email = $input->getArgument('email');
        if (!empty($email)) {
            $member = $this->api->loadMemberByEmail($organization, $email);
            if (!$member) {
                $this->stdErr->writeln(\sprintf('User not found: <error>%s</error>', $email));
                return 1;
            }
        } elseif (!$input->isInteractive()) {
            $this->stdErr->writeln('You must specify the email address of a user to view (in non-interactive mode).');
            return 1;
        } else {
            $member = $this->chooseMember($organization);
        }

        $data = $member->getProperties();
        $memberInfo = $member->getUserInfo();

        // Convert the user ref object to the 'user' property.
        if (isset($data['ref:users'][$member->user_id]) && $data['ref:users'][$member->user_id] instanceof UserRef) {
            $data['user'] = $data['ref:users'][$member->user_id]->getProperties();
            unset($data['ref:users']);
        }

        if ($input->getOption('property')) {
            $this->propertyFormatter->displayData($output, $data, $input->getOption('property'));
            return 0;
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(\sprintf('Viewing the user <info>%s</info> on the organization %s', $this->memberLabel($member), $this->api->getOrganizationLabel($organization)));
        }

        $headings = [];
        $values = [];
        foreach ($data as $key => $value) {
            $headings[] = new AdaptiveTableCell($key, ['wrap' => false]);
            $values[] = $this->propertyFormatter->format($value, $key);
        }

        $this->table->renderSimple($values, $headings);

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(\sprintf("To view the user's project access, run: <info>%s</info>", $this->otherCommandExample($input, 'org:user:projects', $memberInfo ? OsUtil::escapeShellArg($memberInfo->email) : '')));
        }

        return 0;
    }
}
