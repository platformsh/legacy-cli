<?php

namespace Platformsh\Cli;

use GuzzleHttp\Event\BeforeEvent;
use GuzzleHttp\Event\CompleteEvent;
use GuzzleHttp\Event\RequestEvents;
use GuzzleHttp\Event\SubscriberInterface;
use GuzzleHttp\Message\AbstractMessage;
use GuzzleHttp\Message\MessageInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GuzzleDebugSubscriber implements SubscriberInterface
{
    private $stdErr;
    private $includeHeaders;

    private static $requestSeq;

    public function __construct(OutputInterface $output, $includeHeaders = false)
    {
        $this->includeHeaders = $includeHeaders;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    public function getEvents()
    {
        return [
            // Errors are not handled: they are already printed via exceptions.
            'before' => ['onBefore', RequestEvents::LATE],
            'complete' => ['onComplete', RequestEvents::LATE],
        ];
    }

    public function onBefore(BeforeEvent $event)
    {
        if ($this->stdErr->isVeryVerbose()) {
            $req = $event->getRequest();
            if (!$req->getConfig()->hasKey('started_at')) {
                $req->getConfig()->set('started_at', microtime(true));
            }
            if ($req->getConfig()->hasKey('seq')) {
                $seq = $req->getConfig()->get('seq');
            } else {
                if (self::$requestSeq === null) {
                    self::$requestSeq = 1;
                }
                $seq = self::$requestSeq++;
                $req->getConfig()->set('seq', $seq);
            }
            $this->stdErr->writeln(sprintf(
                '<options=reverse>></> Making HTTP request #%d: %s',
                $seq, $this->formatMessage($req, '> ')
            ));
        }
    }

    private function formatMessage(MessageInterface $message, $headerPrefix = '')
    {
        $startLine = AbstractMessage::getStartLine($message);
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

    public function onComplete(CompleteEvent $event)
    {
        if (!$this->stdErr->isVeryVerbose() || !$event->hasResponse()) {
            return;
        }
        $seq = $event->getRequest()->getConfig()->get('seq');
        if (($startedAt = $event->getRequest()->getConfig()->get('started_at')) !== null) {
            $this->stdErr->writeln(sprintf(
                '<options=reverse>\<</> Received response for #%d after %d ms: %s',
                $seq, (microtime(true) - $startedAt) * 1000, $this->formatMessage($event->getResponse(), '< ')
            ));
        } else {
            $this->stdErr->writeln(sprintf(
                '<options=reverse>\<</> Received response for #%d: %s',
                $seq, $this->formatMessage($event->getResponse(), '< ')
            ));
        }
    }
}
