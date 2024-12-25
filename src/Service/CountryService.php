<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

class CountryService
{
    /** @var array<string, string> */
    private array $cache;

    /**
     * Returns a list of countries, keyed by 2-letter country code.
     *
     * @return array<string, string>
     */
    public function listCountries(): array
    {
        if (isset($this->cache)) {
            return $this->cache;
        }
        $filename = CLI_ROOT . '/resources/cldr/countries.json';
        $data = \json_decode((string) \file_get_contents($filename), true);
        if (!$data) {
            throw new \RuntimeException('Failed to read CLDR file: ' . $filename);
        }
        return $this->cache = $data;
    }

    /**
     * Normalizes a given country, transforming it into a country code, if possible.
     *
     * @param string $country
     *
     * @return string
     */
    public function countryToCode(string $country): string
    {
        $countryList = $this->listCountries();
        if (isset($countryList[$country])) {
            return $country;
        }
        // Exact match.
        if (($code = \array_search($country, $countryList)) !== false) {
            return $code;
        }
        // Case-insensitive match.
        $lower = \strtolower($country);
        foreach ($countryList as $code => $name) {
            if ($lower === \strtolower($name) || $lower === \strtolower($code)) {
                return $code;
            }
        }
        return $country;
    }
}
