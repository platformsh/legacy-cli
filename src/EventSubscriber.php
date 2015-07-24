<?php
namespace Platformsh\Cli;

use GuzzleHttp\Exception\ConnectException;
use Platformsh\Cli\Exception\ConnectionFailedException;
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
    }
}
