#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * @file
 * Fetches SSH host keys for Platform.sh public regions.
 */

use Platformsh\Cli\Service\Api;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

require dirname(__DIR__) . '/vendor/autoload.php';

$output = new ConsoleOutput();
$stdErr = $output->getErrorOutput();
$api = new Api(null, null, $output);

if (!$api->isLoggedIn()) {
    $stdErr->writeln('Skipping updating the list of regions from the API (not logged in)');
    exit(0);
}

$stdErr->writeln('Fetching the list of regions from the API...');
$regions = $api->getClient()->getRegions();

$regionDomains = [];
foreach ($regions as $region) {
    if ($region->private) {
        continue;
    }
    $domain = \parse_url($region->endpoint, PHP_URL_HOST);
    if (!$domain) {
        $stdErr->writeln("Failed to parse hostname for region: " . $region['id']);
        continue;
    }
    $regionDomains[] = $domain;
}

$stdErr->writeln(\count($regionDomains) . ' region domain(s) found');

$scanners = [];
foreach ($regionDomains as $regionDomain) {
    foreach (['git.', 'ssh.'] as $prefix) {
        $proc = new Process(['ssh-keyscan', $prefix . $regionDomain]);
        $proc->start();
        $scanners[$prefix . $regionDomain] = $proc;
    }
}

$stdErr->writeln(\count($scanners) . ' ssh-keyscan processes started');

$scannedHosts = [];
while (count($scanners)) {
    foreach ($scanners as $host => $scanner) {
        if (!$scanner->isRunning()) {
            if ($scanner->isSuccessful()) {
                $stdErr->writeln('Scanned host ' . $host);
                $scannedHosts[$host] = trim($scanner->getOutput());
            } else {
                $stdErr->writeln(sprintf('Failed to scan host %s: %s', $host, $scanner->getErrorOutput()));
                exit(1);
            }
            unset($scanners[$host]);
        }
    }
    usleep(300000);
}

ksort($scannedHosts);

$fs = new Filesystem();

$keys_filename = dirname(__DIR__) . '/resources/ssh/host-keys';
$fs->dumpFile($keys_filename, implode("\n", array_values($scannedHosts)) . "\n");
$stdErr->writeln(sprintf('Written to file: <info>%s</info>', $keys_filename));
