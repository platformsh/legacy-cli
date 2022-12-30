<?php
namespace Platformsh\Cli\Command\Organization;

use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

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

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('organization:list')
            ->setAliases(['orgs', 'organizations'])
            ->setDescription('List organizations')
            ->addOption('my', null, InputOption::VALUE_NONE, 'List only the organizations you own')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'An organization property to sort by')
            ->addOption('reverse', null, InputOption::VALUE_NONE, 'Sort in reverse order');
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $client = $this->api()->getClient();
        $userId = $this->api()->getMyUserId();

        if ($input->getOption('my')) {
            $organizations = $client->listOrganizationsByOwner($userId);
        } else {
            $organizations = $client->listOrganizationsWithMember($userId);
        }

        if ($sortBy = $input->getOption('sort')) {
            $this->api()->sortResources($organizations, $sortBy);
        }
        if ($input->getOption('reverse')) {
            $organizations = array_reverse($organizations, true);
        }

        $executable = $this->config()->get('application.executable');
        if (empty($organizations)) {
            $this->stdErr->writeln('No organizations found.');
            if ($this->config()->isCommandEnabled('organization:create')) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln(\sprintf('To create a new organization, run: <info>%s org:create</info>', $executable));
            }
            return 1;
        }

        $rows = [];
        foreach ($organizations as $org) {
            $row = $org->getProperties();
            $info = $org->getOwnerInfo();
            $row['owner_email'] = $info ? $info->email : '';
            $row['owner_username'] = $info ? $info->username : '';
            $rows[] = $row;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        if (!$table->formatIsMachineReadable()) {
            if ($input->getOption('my')) {
                $this->stdErr->writeln('Organizations you own:');
            } else {
                $this->stdErr->writeln('Organizations you own or belong to:');
            }
        }

        $table->render($rows, $this->tableHeader, $this->defaultColumns);

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(\sprintf('To view or modify organization details, run: <info>%s org:info [-o organization]</info>', $executable));
            $this->stdErr->writeln(\sprintf('To see all organization commands run: <info>%s list organization</info>', $executable));
        }

        return 0;
    }
}
