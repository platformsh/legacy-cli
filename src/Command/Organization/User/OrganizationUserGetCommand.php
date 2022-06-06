<?php

namespace Platformsh\Cli\Command\Organization\User;

use Platformsh\Cli\Command\Organization\OrganizationCommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Organization\Member;
use Platformsh\Client\Model\Ref\UserRef;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationUserGetCommand extends OrganizationCommandBase
{
    protected static $defaultName = 'organization:user:get';
    protected static $defaultDescription = 'View an organization user';

    private $api;
    private $formatter;
    private $questionHelper;
    private $selector;
    private $table;

    public function __construct(Config $config, Api $api, PropertyFormatter $formatter, QuestionHelper $questionHelper, Selector $selector, Table $table)
    {
        $this->api = $api;
        $this->formatter = $formatter;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        $this->table = $table;
        parent::__construct($config);
    }

    protected function configure()
    {
        $this->selector->addOrganizationOptions($this->getDefinition());
        $this->addArgument('email', InputArgument::OPTIONAL, 'The email address of the user')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'A property to display');
        $this->formatter->configureInput($this->getDefinition());
        $this->table->configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organization = $this->selector->selectOrganization($input, 'members');

        if (!$organization->hasLink('members')) {
            $this->stdErr->writeln('You do not have permission to view users in the organization ' . $this->api->getOrganizationLabel($organization, 'comment') . '.');
            return 1;
        }

        $member = false;
        $email = $input->getArgument('email');
        if (!empty($email)) {
            foreach ($organization->getMembers() as $m) {
                if ($info = $m->getUserInfo()) {
                    if ($info->email === $email) {
                        $member = $m;
                        break;
                    }
                }
            }
            if (!$member) {
                $this->stdErr->writeln(\sprintf('User not found: <error>%s</error>', $email));
                return 1;
            }
        } elseif (!$input->isInteractive()) {
            $this->stdErr->writeln('You must specify the email address of a user to view (in non-interactive mode).');
            return 1;
        } else {
            $options = [];
            $byID = [];
            foreach ($organization->getMembers() as $m) {
                $byID[$m->id] = $m;
                $info = $m->getUserInfo();
                $options[$m->id] = $info ? \sprintf('%s (%s)', \trim($info->first_name . ' ' . $info->last_name), $info->email) : $m->user_id;
            }
            $id = $this->questionHelper->choose($options, 'Enter a number to choose a user:');
            $member = $byID[$id];
        }

        $data = $member->getProperties();

        // Convert the user ref object to the 'user' property.
        if (isset($data['ref:users'][$member->user_id]) && $data['ref:users'][$member->user_id] instanceof UserRef) {
            $data['user'] = $data['ref:users'][$member->user_id]->getProperties();
            unset($data['ref:users']);
        }

        if ($input->getOption('property')) {
            $this->formatter->displayData($output, $data, $input->getOption('property'));
            return 0;
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(\sprintf('Viewing the user <info>%s</info> on the organization %s', $this->memberLabel($member), $this->api->getOrganizationLabel($organization)));
        }

        $headings = [];
        $values = [];
        foreach ($data as $key => $value) {
            $headings[] = new AdaptiveTableCell($key, ['wrap' => false]);
            $values[] = $this->formatter->format($value, $key);
        }

        $this->table->renderSimple($values, $headings);

        return 0;
    }

    protected function memberLabel(Member $member)
    {
        if ($info = $member->getUserInfo()) {
            return $info->email;
        }

        return $member->id;
    }
}
