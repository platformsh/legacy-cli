<?php
namespace Platformsh\Cli\Command\Auth;

use CommerceGuys\Guzzle\Oauth2\AccessToken;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Url;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\ApiTokenStorage;
use Platformsh\Client\OAuth2\ApiToken;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class ApiTokenLoginCommand extends CommandBase
{

    protected function configure()
    {
        $service = $this->config()->get('service.name');
        $accountsUrl = $this->config()->get('service.accounts_url');
        $executable = $this->config()->get('application.executable');

        $this->setName('auth:api-token-login');
        if ($this->config()->get('application.login_method') === 'api-token') {
            $this->setAliases(['login']);
        }

        $this->setDescription('Log in to ' . $service . ' using an API token');

        $help = 'Use this command to log in to your ' . $service . ' account using an API token.'
            . "\n\nYou can create an account at:\n    <info>" . $accountsUrl . '</info>'
            . "\n\nIf you have an account, but you do not already have an API token, you can create one here:\n    <info>"
            . $accountsUrl . '/user/api-tokens</info>'
            . "\n\nAlternatively, to log in to the CLI with a browser, run:\n    <info>"
            . $executable . ' auth:browser-login</info>';
        if ($aHelp = $this->getApiTokenHelp()) {
            $help .= "\n\n" . $aHelp;
        }
        $this->setHelp($help);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this->api()->hasApiToken(false)) {
            $this->stdErr->writeln('Cannot log in: an API token is already set via config');
            return 1;
        }
        if (!$input->isInteractive()) {
            $this->stdErr->writeln('Non-interactive use of this command is not supported.');
            if ($aHelp = $this->getApiTokenHelp('comment')) {
                $this->stdErr->writeln("\n" . $aHelp);
            }
            return 1;
        }

        $tokenClient = new Client($this->api()->getGuzzleOptions());
        $tokenUrl = Url::fromString($this->config()->get('api.accounts_api_url'))
            ->combine('/oauth2/token')
            ->__toString();

        $validator = function ($apiToken) use ($tokenClient, $tokenUrl) {
            $apiToken = trim($apiToken);
            if (!strlen($apiToken)) {
                throw new \RuntimeException('The token cannot be empty');
            }

            try {
                $token = (new ApiToken($tokenClient, [
                    'client_id' => $this->config()->get('api.oauth2_client_id'),
                    'token_url' => $tokenUrl,
                    'auth_location' => 'headers',
                    'api_token' => $apiToken,
                ]))->getToken();
            } catch (BadResponseException $e) {
                if ($this->exceptionMeansInvalidToken($e)) {
                    throw new \RuntimeException('Invalid API token');
                }
                throw $e;
            }

            // Finalise login.
            $this->stdErr->writeln('');
            $this->stdErr->writeln('The API token is valid.');
            $this->saveTokens($apiToken, $token);

            return $apiToken;
        };

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $question = new Question("Please enter an API token:\n> ");
        $question->setValidator($validator);
        $question->setMaxAttempts(5);
        $questionHelper->ask($input, $output, $question);

        $info = $this->api()->getClient(false, true)->getAccountInfo();
        if (isset($info['username'], $info['mail'])) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'You are logged in as <info>%s</info> (%s).',
                $info['username'],
                $info['mail']
            ));
        }

        return 0;
    }

    /**
     * @param string      $apiToken
     * @param AccessToken $accessToken
     */
    private function saveTokens($apiToken, AccessToken $accessToken) {
        /** @var ApiTokenStorage $storage */
        $storage = $this->getService('api_token_storage');
        $storage->storeToken($apiToken);
        $this->api()->getClient(false, true)->getConnector()->saveToken($accessToken);

        /** @var \Doctrine\Common\Cache\CacheProvider $cache */
        $cache = $this->getService('cache');
        $cache->flushAll();
    }

    /**
     * @param \Exception $e
     *
     * @return bool
     */
    private function exceptionMeansInvalidToken(\Exception $e) {
        if (!$e instanceof BadResponseException || !$e->getResponse() || $e->getResponse()->getStatusCode() !== 400) {
            return false;
        }
        $json = $e->getResponse()->json();
        if (isset($json['error'], $json['error_description'])
            && $json['error'] === 'invalid_grant'
            && stripos($json['error_description'], 'Invalid API token') !== false) {
            return true;
        }

        return false;
    }
}
