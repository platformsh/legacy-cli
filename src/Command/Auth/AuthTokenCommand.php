<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Auth;

use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'auth:token', description: 'Obtain an OAuth 2 access token for API requests')]
class AuthTokenCommand extends CommandBase
{
    public const RFC6750_PREFIX = 'Authorization: Bearer ';

    protected bool $hiddenInList = true;
    public function __construct(private readonly Api $api, private readonly Config $config)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('header', 'H', InputOption::VALUE_NONE, 'Prefix the token with "' . self::RFC6750_PREFIX . '" to make an RFC 6750 header')
            ->addOption('no-warn', 'W', InputOption::VALUE_NONE, 'Suppress the warning that is printed by default to stderr.'
                . ' This option is preferred over redirecting stderr, as that would hide other potentially useful messages.');
        $help = \wordwrap(
            'This command prints a valid OAuth 2 access token to stdout. It can be used to make API requests via standard Bearer authentication (RFC 6750).'
            . "\n\n" . '<comment>Warning: access tokens must be kept secret.</comment>'
            . "\n\n" . 'Using this command is not generally recommended, as it increases the chance of the token being leaked.'
            . ' Take care not to expose the token in a shared program or system, or to send the token to the wrong API domain.',
        );
        $executable = $this->config->getStr('application.executable');
        $apiUrl = $this->config->getApiUrl();
        $examples = [
            'Print the payload for JWT-formatted tokens' => \sprintf('%s auth:token -W | cut -d. -f2 | base64 -d', $executable),
            'Use the token in a curl command' => \sprintf('curl -H"$(%s auth:token -HW)" %s/users/me', $executable, rtrim($apiUrl, '/')),
        ];
        $help .= "\n\n<comment>Examples:</comment>";
        foreach ($examples as $description => $example) {
            $help .= "\n\n$description:\n  <info>$example</info>";
        }
        $this->setHelp($help);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('no-warn')) {
            $this->stdErr->writeln(
                '<fg=yellow>Warning: keep access tokens secret.</>',
            );
        }

        $token = $this->api->getAccessToken();

        $output->write($input->getOption('header') ? self::RFC6750_PREFIX . $token : $token);

        return 0;
    }
}
