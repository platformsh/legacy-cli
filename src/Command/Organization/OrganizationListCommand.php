<?php
namespace Platformsh\Cli\Command\Organization;

use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'organization:list', description: 'List organizations', aliases: ['orgs', 'organizations'])]
class OrganizationListCommand extends OrganizationCommandBase
{
    private $tableHeader = [
        'id' => 'ID',
        'name' => 'Name',
        'label' => 'Label',
        'created_at' => 'Created at',
        'updated_at' => 'Updated at',
        'owner_id' => 'Owner ID',
        'owner_email' => 'Owner email',
        'owner_username' => 'Owner username',
    ];
    private $defaultColumns = ['name', 'label', 'owner_email'];
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Table $table)
    {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addOption('my', null, InputOption::VALUE_NONE, 'List only the organizations you own')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'An organization property to sort by')
            ->addOption('reverse', null, InputOption::VALUE_NONE, 'Sort in reverse order');
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

        if ($sortBy = $input->getOption('sort')) {
            $this->api->sortResources($organizations, $sortBy);
        }
        if ($input->getOption('reverse')) {
            $organizations = array_reverse($organizations, true);
        }

        $executable = $this->config->get('application.executable');
        if (empty($organizations)) {
            $this->stdErr->writeln('No organizations found.');
            if ($this->config->isCommandEnabled('organization:create')) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(\sprintf('To create a new organization, run: <info>%s org:create</info>', $executable));
            }
            return 1;
        }

        $currentProjectOrg = null;
        $currentProject = $this->getCurrentProject(true);
        if ($currentProject && $currentProject->hasProperty('organization')) {
            $currentProjectOrg = $currentProject->getProperty('organization');
        }

        $table = $this->table;

        $rows = [];
        $machineReadable = $table->formatIsMachineReadable();
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

        $table->render($rows, $this->tableHeader, $this->defaultColumns);

        if ($markedCurrent) {
            $this->stdErr->writeln("<info>*</info> - Indicates the current project's organization");
        }

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(\sprintf('To view or modify organization details, run: <info>%s org:info [-o organization]</info>', $executable));
            $this->stdErr->writeln(\sprintf('To see all organization commands run: <info>%s list organization</info>', $executable));
        }

        return 0;
    }
}
