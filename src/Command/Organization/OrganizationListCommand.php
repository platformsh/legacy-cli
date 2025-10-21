<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Organization;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Platformsh\Client\Model\Organization\Organization;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'organization:list', description: 'List organizations', aliases: ['orgs', 'organizations'])]
class OrganizationListCommand extends OrganizationCommandBase
{
    /** @var array<string, string> */
    private array $tableHeader = [
        'id' => 'ID',
        'name' => 'Name',
        'label' => 'Label',
        'type' => 'Type',
        'created_at' => 'Created at',
        'updated_at' => 'Updated at',
        'owner_id' => 'Owner ID',
        'owner_email' => 'Owner email',
        'owner_username' => 'Owner username',
    ];
    /** @var string[] */
    private array $defaultColumns = ['name', 'label', 'owner_email'];
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('my', null, InputOption::VALUE_NONE, 'List only the organizations you own')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'An organization property to sort by')
            ->addOption('reverse', null, InputOption::VALUE_NONE, 'Sort in reverse order');

        if ($this->config->get('api.organization_types')) {
            $this->addOption('type', null, InputOption::VALUE_REQUIRED, 'Filter organizations by type');
            $this->defaultColumns = ['name', 'label', 'type', 'owner_email'];
        }

        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = $this->api->getClient();
        $userId = $this->api->getMyUserId();

        if ($input->getOption('my')) {
            $organizations = $client->listOrganizationsByOwner($userId);
        } else {
            $organizations = $client->listOrganizationsWithMember($userId);
        }

        if ($input->hasOption('type') && ($type = $input->getOption('type'))) {
            $organizations = array_filter($organizations, function (Organization $org) use ($type) {
                return $org->getProperty('type', false) === $type;
            });
        }
        if ($sortBy = $input->getOption('sort')) {
            $this->api->sortResources($organizations, $sortBy);
        }
        if ($input->getOption('reverse')) {
            $organizations = array_reverse($organizations, true);
        }

        $executable = $this->config->getStr('application.executable');
        if (empty($organizations)) {
            $this->stdErr->writeln('No organizations found.');
            if ($this->config->isCommandEnabled('organization:create')) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(\sprintf('To create a new organization, run: <info>%s org:create</info>', $executable));
            }
            return 1;
        }

        $currentProjectOrg = null;
        $currentProject = $this->selector->getCurrentProject(true);
        if ($currentProject && $currentProject->hasProperty('organization')) {
            $currentProjectOrg = $currentProject->getProperty('organization');
        }

        $rows = [];
        $machineReadable = $this->table->formatIsMachineReadable();
        $markedCurrent = false;
        foreach ($organizations as $org) {
            $row = $org->getProperties();
            if (!$machineReadable && $org->id === $currentProjectOrg) {
                $row['name'] .= '<info>*</info>';
                $markedCurrent = true;
            }
            $info = $org->getOwnerInfo();
            $row['owner_email'] = $info ? $info->email : '';
            $row['owner_username'] = $info ? $info->username : '';
            $rows[] = $row;
        }

        if (!$machineReadable) {
            if ($input->getOption('my')) {
                $this->stdErr->writeln('Organizations you own:');
            } else {
                $this->stdErr->writeln('Organizations you own or belong to:');
            }
        }

        $this->table->render($rows, $this->tableHeader, $this->defaultColumns);

        if ($markedCurrent) {
            $this->stdErr->writeln("<info>*</info> - Indicates the current project's organization");
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(\sprintf('To view or modify organization details, run: <info>%s org:info [-o organization]</info>', $executable));
            $this->stdErr->writeln(\sprintf('To see all organization commands run: <info>%s list organization</info>', $executable));
        }

        return 0;
    }
}
