<?php
namespace Platformsh\Cli\Command\Team\User;

use Platformsh\Cli\Command\Team\TeamCommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Team\TeamMember;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TeamUserListCommand extends TeamCommandBase
{
    private $tableHeader = [
        'id' => 'User ID',
        'email' => 'Email address',
        'created_at' => 'Date added',
        'updated_at' => 'Updated at',
    ];
    private $defaultColumns = ['id', 'email', 'created_at'];

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('team:user:list')
            ->setAliases(['team:users'])
            ->setDescription('List users in a team')
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'The number of items to display per page. Use 0 to disable pagination')
            ->addOrganizationOptions()
            ->addTeamOption();
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $options = [];

        $count = $input->getOption('count');
        $itemsPerPage = (int) $this->config()->getWithDefault('pagination.count', 20);
        if ($count !== null && $count !== '0') {
            if (!\is_numeric($count) || $count > 50) {
                $this->stdErr->writeln('The --count must be a number between 1 and 50, or 0 to disable pagination.');
                return 1;
            }
            $itemsPerPage = $count;
        }
        $options['query']['page[size]'] = $itemsPerPage;

        $fetchAllPages = !$this->config()->getWithDefault('pagination.enabled', true);
        if ($count === '0') {
            $fetchAllPages = true;
        }

        $team = $this->validateTeamInput($input);
        if (!$team) {
            return 1;
        }

        $httpClient = $this->api()->getHttpClient();
        /** @var TeamMember[] $members */
        $members = [];
        $url = $team->getUri() . '/members';
        $progress = new ProgressMessage($output);
        $pageNumber = 1;
        do {
            if ($pageNumber > 1) {
                $progress->showIfOutputDecorated(sprintf('Loading users (page %d)...', $pageNumber));
            }
            $result = TeamMember::getCollectionWithParent($url, $httpClient, $options);
            $progress->done();
            $members = \array_merge($members, $result['items']);
            $url = $result['collection']->getNextPageUrl();
            $pageNumber++;
        } while ($url && $fetchAllPages);

        if (empty($members)) {
            $this->stdErr->writeln(\sprintf('No users were found in the team %s.', $this->getTeamLabel($team)));
            $executable = $this->config()->get('application.executable');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(\sprintf('To add a user, run: <info>%s team:user:add [email]</info>', $executable));
            return 0;
        }

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $rows = [];
        foreach ($members as $member) {
            $rows[] = [
                'id' => new AdaptiveTableCell($member->user_id, ['wrap' => false]),
                'email' => $formatter->format($member->getUserInfo()->email, 'email'),
                'created_at' => $formatter->format($member->created_at, 'created_at'),
                'updated_at' => $formatter->format($member->updated_at, 'updated_at'),
            ];
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(\sprintf('Users in the team %s:', $this->getTeamLabel($team)));
        }

        $table->render($rows, $this->tableHeader, $this->defaultColumns);

        if (!$table->formatIsMachineReadable()) {
            if (isset($collection['next'])) {
                $this->stdErr->writeln('More users are available');
                $this->stdErr->writeln('List all items with: <info>--count 0</info> (<info>-c0</info>)');
            }

            $executable = $this->config()->get('application.executable');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(\sprintf('To add a user, run: <info>%s team:user:add [email]</info>', $executable));
            $this->stdErr->writeln(\sprintf('To delete a user, run: <info>%s team:user:delete [email]</info>', $executable));
        }

        return 0;
    }
}
