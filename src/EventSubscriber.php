<?php
namespace Platformsh\Cli;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ParseException;
use Platformsh\Cli\Exception\ConnectionFailedException;
use Platformsh\Cli\Exception\LoginRequiredException;
use Symfony\Component\Console\Event\ConsoleExceptionEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class EventSubscriber implements EventSubscriberInterface
{
    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return array('console.exception' => 'onException');
    }

    /**
     * React to any console exceptions.
     *
     * @param ConsoleExceptionEvent    $event
     */
    public function onException(ConsoleExceptionEvent $event)
    {
        $exception = $event->getException();

        // Replace Guzzle connect exceptions with a friendlier message. This
        // also prevents the user from seeing two exceptions (one direct from
        // Guzzle, one from RingPHP).
        if ($exception instanceof ConnectException && strpos($exception->getMessage(), 'cURL error 6') !== false) {
            $request = $exception->getRequest();
            $event->setException(new ConnectionFailedException(
              "Failed to connect to host: " . $request->getHost()
              . " \nRequest URL: " . $request->getUrl()
              . " \nPlease check your Internet connection"
            ));
            $event->stopPropagation();
        }

        // Handle Guzzle client exceptions, i.e. HTTP 4xx errors.
        if ($exception instanceof ClientException && ($response = $exception->getResponse())) {
            try {
                $response->getBody()->seek(0);
                $json = $response->json();
            }
            catch (ParseException $e) {
                $json = [];
            }

            // Create a friendlier message for the OAuth2 "Invalid refresh token"
            // error.
            if ($response->getStatusCode() === 400 && isset($json['error_description']) && $json['error_description'] === 'Invalid refresh token') {
                $event->setException(new LoginRequiredException(
                    "Invalid refresh token: please log in again."
                ));
                $event->stopPropagation();
            }
            elseif ($response->getStatusCode() === 401) {
                $event->setException(new LoginRequiredException(
                    "Unauthorized: please log in again."
                ));
                $event->stopPropagation();
            }
        }
    }
}
