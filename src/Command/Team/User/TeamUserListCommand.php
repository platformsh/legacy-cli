<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Team\User;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Command\Team\TeamCommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Team\TeamMember;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'team:user:list', description: 'List users in a team', aliases: ['team:users'])]
class TeamUserListCommand extends TeamCommandBase
{
    /** @var array<string, string> */
    private array $tableHeader = [
        'id' => 'User ID',
        'email' => 'Email address',
        'created_at' => 'Date added',
        'updated_at' => 'Updated at',
    ];
    /** @var string[] */
    private array $defaultColumns = ['id', 'email', 'created_at'];
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'The number of items to display per page. Use 0 to disable pagination');
        $this->selector->addOrganizationOptions($this->getDefinition());
        $this->addTeamOption();
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = [];

        $count = $input->getOption('count');
        $itemsPerPage = $this->config->getInt('pagination.count');
        if ($count !== null && $count !== '0') {
            if (!\is_numeric($count) || $count > 50) {
                $this->stdErr->writeln('The --count must be a number between 1 and 50, or 0 to disable pagination.');
                return 1;
            }
            $itemsPerPage = $count;
        }
        $options['query']['page[size]'] = $itemsPerPage;

        $fetchAllPages = !$this->config->getBool('pagination.enabled');
        if ($count === '0') {
            $fetchAllPages = true;
        }

        $team = $this->validateTeamInput($input);
        if (!$team) {
            return 1;
        }

        $httpClient = $this->api->getHttpClient();
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
            $executable = $this->config->getStr('application.executable');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(\sprintf('To add a user, run: <info>%s team:user:add [email]</info>', $executable));
            return 0;
        }

        $rows = [];
        foreach ($members as $member) {
            $rows[] = [
                'id' => new AdaptiveTableCell($member->user_id, ['wrap' => false]),
                'email' => $this->propertyFormatter->format($member->getUserInfo()->email, 'email'),
                'created_at' => $this->propertyFormatter->format($member->created_at, 'created_at'),
                'updated_at' => $this->propertyFormatter->format($member->updated_at, 'updated_at'),
            ];
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(\sprintf('Users in the team %s:', $this->getTeamLabel($team)));
        }

        $this->table->render($rows, $this->tableHeader, $this->defaultColumns);

        if (!$this->table->formatIsMachineReadable()) {
            if ($result['collection']->hasNextPage()) {
                $this->stdErr->writeln('More users are available');
                $this->stdErr->writeln('List all items with: <info>--count 0</info> (<info>-c0</info>)');
            }

            $executable = $this->config->getStr('application.executable');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(\sprintf('To add a user, run: <info>%s team:user:add [email]</info>', $executable));
            $this->stdErr->writeln(\sprintf('To delete a user, run: <info>%s team:user:delete [email]</info>', $executable));
        }

        return 0;
    }
}
