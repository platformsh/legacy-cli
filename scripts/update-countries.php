#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * @file
 * Downloads country codes and English names from the Unicode CLDR project.
 *
 * This process is borrowed from: https://github.com/bojanz/address
 */

$url = 'https://raw.githubusercontent.com/unicode-org/cldr-json/main/cldr-json/cldr-localenames-full/main/en/territories.json';

fputs(STDERR, "Fetching CLDR countries data\n");

$contents = file_get_contents($url);
if (!$contents) {
    throw new \RuntimeException('Failed to read CLDR file: ' . $url);
}

$data = \json_decode($contents, true);
if (!$data) {
    throw new \RuntimeException('Failed to decode JSON in CLDR file: ' . $url);
}

if (empty($data['main']['en']['identity']['language'])) {
    throw new \RuntimeException('Failed to read language info in CLDR file: ' . $url);
}
if ($data['main']['en']['identity']['language'] !== 'en') {
    throw new \RuntimeException('Unexpected language info in CLDR file: ' . $url);
}
if (empty($data['main']['en']['localeDisplayNames']['territories'])) {
    throw new \RuntimeException('Failed to find territories list in CLDR file: ' . $url);
}

fputs(STDERR, "Processing country data.\n");

$territories = $data['main']['en']['localeDisplayNames']['territories'];
$nonCountries = ['EU', 'EZ', 'UN', 'QO', 'XA', 'XB', 'ZZ'];
$countries = [];
foreach ($territories as $code => $territory) {
    if (\strlen((string) $code) === 2 && !\in_array($code, $nonCountries, true)) {
        $countries[$code] = $territory;
    }
}

$filename = dirname(__DIR__) . '/resources/cldr/countries.json';

if (!file_put_contents($filename, \json_encode($countries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
    throw new \RuntimeException('Failed to write file: ' . $filename);
}

fputs(STDERR, "Countries data has been successfully written to: $filename\n");
