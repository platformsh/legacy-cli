<?php
namespace Platformsh\Cli\Command\Auth;

use CommerceGuys\Guzzle\Oauth2\AccessToken;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\OAuth2\ApiToken;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

class ApiTokenLoginCommand extends CommandBase
{

    protected function configure()
    {
        $service = $this->config()->get('service.name');
        $executable = $this->config()->get('application.executable');

        $this->setName('auth:api-token-login');
        if ($this->config()->get('application.login_method') === 'api-token') {
            $this->setAliases(['login']);
        }

        $this->setDescription('Log in to ' . $service . ' using an API token');

        $help = 'Use this command to log in to your ' . $service . ' account using an API token.'
            . "\n\nYou can create an account at:\n    <info>" . $this->config()->get('service.register_url') . '</info>'
            . "\n\nIf you have an account, but you do not already have an API token, you can create one here:\n    <info>"
            . $this->config()->get('service.api_tokens_url') . '</info>'
            . "\n\nAlternatively, to log in to the CLI with a browser, run:\n    <info>"
            . $executable . ' auth:browser-login</info>';
        $this->setHelp($help);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this->api()->hasApiToken(false)) {
            $this->stdErr->writeln('An API token is already set via config');
            return 1;
        }
        if (!$input->isInteractive()) {
            $this->stdErr->writeln('Non-interactive use of this command is not supported.');
            $this->stdErr->writeln("\n" . $this->getNonInteractiveAuthHelp('comment'));
            return 1;
        }

        $tokenClient = $this->api()->getExternalHttpClient();
        $clientId = $this->config()->get('api.oauth2_client_id');
        $tokenUrl = $this->config()->get('api.oauth2_token_url');

        $validator = function ($apiToken) use ($tokenClient, $clientId, $tokenUrl) {
            $apiToken = trim($apiToken);
            if (!strlen($apiToken)) {
                throw new \RuntimeException('The token cannot be empty');
            }

            try {
                $token = (new ApiToken($tokenClient, [
                    'client_id' => $clientId,
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
        $question->setHidden(true);
        $questionHelper->ask($input, $output, $question);

        $this->finalizeLogin();

        return 0;
    }

    /**
     * Saves the new tokens and safely logs out of the previous session.
     *
     * @param string      $apiToken
     * @param AccessToken $accessToken
     */
    private function saveTokens($apiToken, AccessToken $accessToken) {
        $this->api()->logout();

        /** @var \Platformsh\Cli\Service\TokenConfig $tokenConfig */
        $tokenConfig = $this->getService('token_config');
        $tokenConfig->storage()->storeToken($apiToken);

        $this->api()
            ->getClient(false, true)
            ->getConnector()
            ->saveToken($accessToken);
    }

    /**
     * @param \Exception $e
     *
     * @return bool
     */
    private function exceptionMeansInvalidToken(\Exception $e) {
        if (!$e instanceof BadResponseException || !$e->getResponse() || !in_array($e->getResponse()->getStatusCode(), [400, 401], true)) {
            return false;
        }
        $json = $e->getResponse()->json();
        // Compatibility with legacy auth provider.
        if (isset($json['error'], $json['error_description'])
            && $json['error'] === 'invalid_grant'
            && stripos($json['error_description'], 'Invalid API token') !== false) {
            return true;
        }
        // Compatibility with new auth provider.
        if (isset($json['error'], $json['error_hint'])
            && $json['error'] === 'request_unauthorized'
            && stripos($json['error_hint'], 'API token') !== false) {
            return true;
        }

        return false;
    }
}
