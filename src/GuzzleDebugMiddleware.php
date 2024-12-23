<?php

declare(strict_types=1);

namespace Platformsh\Cli;

use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GuzzleDebugMiddleware
{
    private readonly OutputInterface $stdErr;

    private static int $requestSeq = 1;

    public function __construct(OutputInterface $output, private readonly bool $includeHeaders = false)
    {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    public function __invoke(callable $next): callable
    {
        return function (RequestInterface $request, array $options) use ($next): PromiseInterface {
            if (!$this->stdErr->isVeryVerbose()) {
                return $next($request, $options);
            }
            $started = microtime(true);
            $seq = self::$requestSeq++;

            $this->stdErr->writeln(sprintf(
                '<options=reverse>></> Making HTTP request #%d: %s',
                $seq,
                $this->formatMessage($request, '> '),
            ));

            /** @var PromiseInterface $promise */
            $promise = $next($request, $options);

            return $promise->then(function (ResponseInterface $response) use ($seq, $started): ResponseInterface|PromiseInterface {
                $this->stdErr->writeln(sprintf(
                    '<options=reverse>\<</> Received response for #%d after %d ms: %s',
                    $seq,
                    (microtime(true) - $started) * 1000,
                    $this->formatMessage($response, '< '),
                ));
                return $response;
            });
        };
    }

    private function formatMessage(RequestInterface|ResponseInterface $message, string $headerPrefix = ''): string
    {
        $startLine = $message instanceof RequestInterface
            ? $this->getRequestFirstLine($message)
            : $this->getResponseFirstLine($message);
        if (!$this->includeHeaders) {
            return $startLine;
        }
        $headers = '';
        foreach ($message->getHeaders() as $name => $values) {
            if ($name === 'Authorization') {
                $headers .= "\r\n{$headerPrefix}{$name}: [redacted]";
            } else {
                $headers .= "\r\n{$headerPrefix}{$name}: " . implode(', ', $values);
            }
        }
        return $startLine . $headers;
    }

    private function getRequestFirstLine(RequestInterface $request): string
    {
        $method = $request->getMethod();
        $uri = $request->getUri();
        $protocolVersion = $request->getProtocolVersion();

        return sprintf('%s %s HTTP/%s', $method, $uri, $protocolVersion);
    }

    private function getResponseFirstLine(ResponseInterface $response): string
    {
        $statusCode = $response->getStatusCode();
        $reasonPhrase = $response->getReasonPhrase();
        $protocolVersion = $response->getProtocolVersion();

        return sprintf('HTTP/%s %d %s', $protocolVersion, $statusCode, $reasonPhrase);
    }

}
