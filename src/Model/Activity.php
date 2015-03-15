<?php
namespace CommerceGuys\Platform\Cli\Model;

use Guzzle\Http\Client as HttpClient;
use Guzzle\Http\Exception\CurlException;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class Activity extends HalResource
{

    /**
     * Wait for multiple activities to complete.
     *
     * @param array           $apiResponses
     * @param HttpClient      $client
     * @param OutputInterface $output
     */
    public static function waitMultiple(array $apiResponses, HttpClient $client, OutputInterface $output)
    {
        $activities = array();
        foreach ($apiResponses as $apiResponse) {
            if (!empty($apiResponse['_embedded']['activities'][0])) {
                $activities[] = new self($apiResponse['_embedded']['activities'][0], $client);
            }
        }
        $count = count($activities);
        if ($count <= 0) {
            return;
        }
        $complete = 0;
        $output->writeln("Waiting...");
        $bar = new ProgressBar($output);
        $bar->start($count);
        $bar->setFormat('verbose');
        while ($complete < $count) {
            sleep(1);
            foreach ($activities as $activity) {
                if (!$activity->isComplete()) {
                    $activity->refresh();
                } else {
                    $complete++;
                }
            }
            $bar->setCurrent($complete);
        }
        $bar->finish();
        $output->writeln('');
    }

    /**
     * With an API response, wait for an activity to complete and write its log.
     *
     * @param array           $apiResponse
     * @param HttpClient      $client
     * @param OutputInterface $output
     * @param string          $success
     * @param string          $failure
     *
     * @return bool|null
     *   True if the activity succeeded, false if it failed, null if no activity
     *   was found.
     */
    public static function waitAndLog(array $apiResponse, HttpClient $client, OutputInterface $output, $success = null, $failure = null) {
        if (empty($apiResponse['_embedded']['activities'][0])) {
            return null;
        }
        $output->writeln('Waiting for the operation to complete...');
        $activity = new self($apiResponse['_embedded']['activities'][0], $client);
        $activity->wait(
          null,
          function ($log) use ($output) {
              $output->write(preg_replace('/^/m', '    ', $log));
          }
        );
        switch ($activity->getProperty('state')) {
            case 'success':
                if ($success !== null) {
                    $output->writeln($success);
                }
                return true;

            case 'failure':
                if ($failure !== null) {
                    $output->writeln($failure);
                }
        }
        return false;
    }

    /**
     * Wait for the activity to complete.
     *
     * @param callable  $onPoll       A function that will be called every time
     *                                the activity is polled for updates. It
     *                                will be passed one argument: the
     *                                Activity object.
     * @param callable  $onLog        A function that will print new activity log
     *                                messages as they are received. It will be
     *                                passed one argument: the message as a
     *                                string.
     * @param int|float $pollInterval The polling interval, in seconds.
     */
    public function wait($onPoll = null, $onLog = null, $pollInterval = 1)
    {
        $log = $this->getProperty('log');
        if ($onLog !== null && strlen(trim($log))) {
            $onLog(trim($log) . "\n");
        }
        $length = strlen($log);
        $retries = 0;
        while (!$this->isComplete() && $this->getProperty('state') !== 'failure') {
            usleep($pollInterval * 1000000);
            try {
                $this->refresh(['timeout' => $pollInterval]);
                if ($onPoll !== null) {
                    $onPoll($this);
                }
                if ($onLog !== null && ($new = substr($this->getProperty('log'), $length))) {
                    $onLog(trim($new) . "\n");
                    $length += strlen($new);
                }
            } catch (CurlException $e) {
                // Retry on timeout.
                if ($e->getErrorNo() === 28) {
                    $retries++;
                    continue;
                }
                throw $e;
            }
        }
    }

    /**
     * @return bool
     */
    public function isComplete()
    {
        return $this->getProperty('completion_percent') >= 100;
    }

    /**
     * Restore the backup associated with this activity.
     *
     * @return array
     */
    public function restore()
    {
        if ($this->getProperty('type') !== 'environment.backup') {
            throw new \BadMethodCallException('Cannot restore activity (wrong type)');
        }
        return $this->runOperation('restore');
    }

    /**
     * Get a description of this activity.
     *
     * @return string
     */
    public function getDescription()
    {
        $data = $this->getProperties();
        switch ($data['type']) {
            case 'environment.activate':
                return sprintf(
                  "%s activated environment %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['environment']['title']
                );

            case 'environment.backup':
                return sprintf(
                  "%s created backup of %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['environment']['title']
                );

            case 'environment.branch':
                return sprintf(
                  "%s branched %s from %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['outcome']['title'],
                  $data['payload']['parent']['title']
                );

            case 'environment.delete':
                return sprintf(
                  "%s deleted environment %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['environment']['title']
                );

            case 'environment.deactivate':
                return sprintf(
                  "%s deactivated environment %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['environment']['title']
                );

            case 'environment.initialize':
                return sprintf(
                  "%s initialized environment %s with profile %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['outcome']['title'],
                  $data['payload']['profile']
                );

            case 'environment.merge':
                return sprintf(
                  "%s merged %s into %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['outcome']['title'],
                  $data['payload']['environment']['title']
                );

            case 'environment.push':
                return sprintf(
                  "%s pushed to %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['environment']['title']
                );

            case 'environment.restore':
                return sprintf(
                  "%s restored %s to %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['environment'],
                  substr($data['payload']['commit'], 0, 7)
                );

            case 'environment.synchronize':
                $syncedCode = !empty($data['payload']['synchronize_code']);
                if ($syncedCode && !empty($data['payload']['synchronize_data'])) {
                    $syncType = 'code and data';
                } elseif ($syncedCode) {
                    $syncType = 'code';
                } else {
                    $syncType = 'data';
                }
                return sprintf(
                  "%s synced %s's %s with %s",
                  $data['payload']['user']['display_name'],
                  $data['payload']['outcome']['title'],
                  $syncType,
                  $data['payload']['environment']['title']
                );
        }
        return $data['type'];
    }

}
