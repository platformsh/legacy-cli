<?php

declare(strict_types=1);

namespace Platformsh\Cli\Console;

use Doctrine\Common\Cache\CacheProvider;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ServerException;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Exception\ConnectionFailedException;
use Platformsh\Cli\Exception\LoginRequiredException;
use Platformsh\Cli\Exception\PermissionDeniedException;
use Platformsh\Client\Exception\EnvironmentStateException;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

readonly class EventSubscriber implements EventSubscriberInterface
{
    public function __construct(private CacheProvider $cache, private Config $config) {}

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [ConsoleEvents::ERROR => 'onError'];
    }

    /**
     * React to any console errors or exceptions.
     *
     * @param ConsoleErrorEvent $event
     */
    public function onError(ConsoleErrorEvent $event): void
    {
        $error = $event->getError();

        // Replace Guzzle connect exceptions with a friendlier message. This
        // also prevents the user from seeing two exceptions (one direct from
        // Guzzle, one from RingPHP).
        if ($error instanceof ConnectException && str_contains($error->getMessage(), 'cURL error 6')) {
            $request = $error->getRequest();
            $event->setError(new ConnectionFailedException(
                "Failed to connect to host: " . $request->getUri()->getHost()
                . " \nPlease check your Internet connection.",
                $error,
            ));
            $event->stopPropagation();
        }

        // Handle Guzzle exceptions, i.e. HTTP 4xx or 5xx errors.
        if ($error instanceof ClientException || $error instanceof ServerException) {
            $response = $error->getResponse();
            $request = $error->getRequest();
            $json = (array) json_decode($response->getBody()->__toString(), true);

            // Create a friendlier message for the OAuth2 "Invalid refresh token"
            // error.
            if ($response->getStatusCode() === 400
                && isset($json['error_description'])
                && $json['error_description'] === 'Invalid refresh token') {
                $event->setError(new LoginRequiredException(
                    'Invalid refresh token.',
                    $this->config,
                    $error,
                ));
                $event->stopPropagation();
            } elseif ($response->getStatusCode() === 401) {
                $event->setError(new LoginRequiredException(
                    'Unauthorized.',
                    $this->config,
                    $error,
                ));
                $event->stopPropagation();
            } elseif ($response->getStatusCode() === 403) {
                $event->setError(new PermissionDeniedException($this->permissionDeniedMessage($request), $error));
                $event->stopPropagation();
            }
        }

        // When an environment is found to be in the wrong state, perhaps our
        // cache is old - we should invalidate it.
        if ($error instanceof EnvironmentStateException) {
            $this->cache->delete('environments:' . $error->getEnvironment()->project);
        }
    }

    /**
     * Returns a friendlier permission denied error for 403 responses, based on the request URL.
     *
     * @param RequestInterface $request
     * @return string
     */
    private function permissionDeniedMessage(RequestInterface $request): string
    {
        $pathsPermissionTypes = [
            '/projects' => 'project',
            '/subscriptions' => 'project',
            '/environments' => 'environment',
            '/organizations' => 'organization',
        ];
        $requestPath = $request->getUri()->getPath();
        $permissionTypes = [];
        foreach ($pathsPermissionTypes as $path => $pathsPermissionType) {
            if (str_contains($requestPath, $path)) {
                $permissionTypes[$pathsPermissionType] = $pathsPermissionType;
            }
        }
        $message = 'Permission denied.';
        if (count($permissionTypes) > 1) {
            $last = array_pop($permissionTypes);
            $message .= ' Check your permissions on the ' . implode(', ', $permissionTypes) . ' or ' . $last . '.';
        } elseif (count($permissionTypes) === 1) {
            $message .= ' Check your permissions on the ' . reset($permissionTypes) . '.';
        }
        return $message;
    }
}
