<?php
namespace Platformsh\Cli\Console;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Exception\ConnectionFailedException;
use Platformsh\Cli\Exception\HttpException;
use Platformsh\Cli\Exception\LoginRequiredException;
use Platformsh\Cli\Exception\PermissionDeniedException;
use Platformsh\Client\Exception\EnvironmentStateException;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class EventSubscriber implements EventSubscriberInterface
{
    protected $config;

    /**
     * @param \Platformsh\Cli\Service\Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [ConsoleEvents::ERROR => 'onException'];
    }

    /**
     * React to any console errors or exceptions.
     *
     * @param ConsoleErrorEvent $event
     */
    public function onException(ConsoleErrorEvent $event)
    {
        $error = $event->getError();

        // Replace Guzzle connect exceptions with a friendlier message. This
        // also prevents the user from seeing two exceptions (one direct from
        // Guzzle, one from RingPHP).
        if ($error instanceof ConnectException && strpos($error->getMessage(), 'cURL error 6') !== false) {
            $request = $error->getRequest();
            $event->setError(new ConnectionFailedException(
                "Failed to connect to host: " . $request->getHost()
                . " \nPlease check your Internet connection.",
                $error
            ));
            $event->stopPropagation();
        }

        // Handle Guzzle exceptions, i.e. HTTP 4xx or 5xx errors.
        if (($error instanceof ClientException || $error instanceof ServerException)
            && ($response = $error->getResponse())) {
            $request = $error->getRequest();
            $requestConfig = $request->getConfig();
            $json = (array) json_decode($response->getBody()->__toString(), true);

            // Create a friendlier message for the OAuth2 "Invalid refresh token"
            // error.
            if ($response->getStatusCode() === 400
                && isset($json['error_description'])
                && $json['error_description'] === 'Invalid refresh token') {
                $event->setError(new LoginRequiredException(
                    'Invalid refresh token.',
                    $this->config,
                    $error
                ));
                $event->stopPropagation();
            } elseif ($response->getStatusCode() === 401 && $requestConfig['auth'] === 'oauth2') {
                $event->setError(new LoginRequiredException(
                    'Unauthorized.',
                    $this->config,
                    $error
                ));
                $event->stopPropagation();
            } elseif ($response->getStatusCode() === 403 && $requestConfig['auth'] === 'oauth2') {
                $event->setError(new PermissionDeniedException(
                    "Permission denied. Check your project or environment permissions.",
                    $error
                ));
                $event->stopPropagation();
            } else {
                $event->setError(new HttpException(null, $error));
                $event->stopPropagation();
            }
        }

        // When an environment is found to be in the wrong state, perhaps our
        // cache is old - we should invalidate it.
        if ($error instanceof EnvironmentStateException) {
            (new Api())->clearEnvironmentsCache($error->getEnvironment()->project);
        }
    }
}
