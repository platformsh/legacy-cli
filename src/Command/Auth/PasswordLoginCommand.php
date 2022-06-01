<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Auth;

use Doctrine\Common\Cache\CacheProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Login;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Oauth2\Client\Exception\TfaRequiredException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class PasswordLoginCommand extends CommandBase
{
    protected static $defaultName = 'auth:password-login';
    protected $stability = 'deprecated';
    protected $hiddenInList = true;

    private $api;
    private $cache;
    private $config;
    private $login;
    private $questionHelper;

    public function __construct(Api $api, CacheProvider $cache, Config $config, Login $login, QuestionHelper $questionHelper)
    {
        $this->api = $api;
        $this->cache = $cache;
        $this->config = $config;
        $this->login = $login;
        $this->questionHelper = $questionHelper;
        parent::__construct();
    }


    protected function configure()
    {
        $service = $this->config->get('service.name');
        $executable = $this->config->get('application.executable');

        if ($this->config->get('application.login_method') === 'password') {
            $this->setAliases(['login']);
        }

        $this->setHiddenAliases(['auth:login']);
        $this->setDescription('Log in to ' . $service . ' using a username and password');

        $help = 'Use this command to log in to your ' . $service . ' account in the terminal.'
            . "\n\nYou can create an account at:\n    <info>" . $this->config->get('service.register_url') . '</info>'
            . "\n\nIf you have an account, but you do not already have a password, you can set one here:\n    <info>"
            . $this->config->get('service.reset_password_url') . '</info>'
            . "\n\nAlternatively, to log in to the CLI with a browser, run:\n    <info>"
            . $executable . ' auth:browser-login</info>'
            . "\n\n" . $this->login->getNonInteractiveAuthHelp();
        $this->setHelp($help);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this->api->hasApiToken()) {
            $this->stdErr->writeln('Cannot log in: an API token is set');
            return 1;
        }
        if (!$input->isInteractive()) {
            $this->stdErr->writeln('Non-interactive use of this command is not supported.');
            $this->stdErr->writeln("\n" . $this->login->getNonInteractiveAuthHelp('comment'));
            return 1;
        }

        $this->stdErr->writeln(
            'Please log in using your <info>' . $this->config->get('service.name') . '</info> account.'
        );

        $this->stdErr->writeln('');
        $this->stdErr->writeln('<fg=yellow;options=bold,reverse>Warning: </><fg=yellow;options=reverse>This command is deprecated.</>');
        $this->stdErr->writeln(sprintf('Password login will soon be removed in the %s API.', $this->config->get('service.name')));
        $this->stdErr->writeln('');

        $this->configureAccount($input, $this->stdErr);

        $this->cache->flushAll();

        $this->login->finalize();

        return 0;
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface   $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    protected function configureAccount(InputInterface $input, OutputInterface $output)
    {
        $question = new Question('Your email address or username: ');
        $question->setValidator([$this, 'validateUsernameOrEmail']);
        $question->setMaxAttempts(5);
        $email = $this->questionHelper->ask($input, $output, $question);

        $question = new Question('Your password: ');
        $question->setValidator(
            function ($answer) {
                if (trim($answer) == '') {
                    throw new \RuntimeException('The password cannot be empty');
                }

                return $answer;
            }
        );
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $question->setMaxAttempts(5);
        $password = $this->questionHelper->ask($input, $output, $question);

        try {
            $this->api->getClient(false)
                ->getConnector()
                ->logIn($email, $password, true);
        }
        catch (TfaRequiredException $e) {
            // If a two-factor authentication challenge is received, then ask
            // the user for their TOTP code, and then retry logging in.
            $output->writeln("\nTwo-factor authentication is required.");
            $question = new Question('Your application verification code: ');
            $question->setValidator(function ($answer) use ($email, $password) {
                if (trim($answer) == '') {
                    throw new \RuntimeException('The code cannot be empty.');
                }
                $this->api->getClient(false)
                    ->getConnector()
                    ->logIn($email, $password, true, $answer);

                return $answer;
            });
            $question->setMaxAttempts(5);
            $this->questionHelper->ask($input, $output, $question);
        }
        catch (IdentityProviderException $e) {
            $output->writeln([
                '',
                '<error>' . rtrim($e->getMessage(), '.') . '.</error>',
                '',
                'Login failed. Please try again.',
                '',
                "Forgot your password? Or don't have a password yet? Visit:",
                '  <comment>' . $this->config->get('service.accounts_url') . '/user/password</comment>',
                '',
            ]);
            $this->configureAccount($input, $output);
        }
    }

    /**
     * Validation callback for the username or email address.
     *
     * @param string $username
     *
     * @return string
     */
    public function validateUsernameOrEmail($username)
    {
        $username = trim($username);
        if (!strlen($username) || (!filter_var($username, FILTER_VALIDATE_EMAIL) && !$this->validateUsername($username))) {
            throw new \RuntimeException(
                'Please enter a valid email address or username.'
            );
        }

        return $username;
    }

    /**
     * Validate a username.
     *
     * @param string $username
     *
     * @return bool
     */
    protected function validateUsername($username)
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{0,30}[a-z0-9]$/', $username) === 1;
    }
}
