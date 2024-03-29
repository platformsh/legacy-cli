<?php

namespace Platformsh\Cli\Tests\Util;

use Platformsh\Cli\Util\TimezoneUtil;

class TimezoneUtilTest extends \PHPUnit_Framework_TestCase
{
    private $originalSetting;
    private $originalIni;
    private $originalEnv;

    public function setUp()
    {
        // Reset to PHP defaults.
        $this->originalIni = ini_get('date.timezone');
        $this->originalSetting = date_default_timezone_get();
        $this->originalEnv = getenv('TZ');
        ini_set('date.timezone', 'UTC');
        date_default_timezone_set('UTC');
        putenv('TZ=');
    }

    public function tearDown()
    {
        // Reset to original settings.
        ini_set('date.timezone', $this->originalIni);
        date_default_timezone_set($this->originalSetting);
        if ($this->originalEnv !== false) {
            putenv('TZ=' . $this->originalEnv);
        }
    }

    public function testGetTimezoneReturnsIni()
    {
        // Pick a rare timezone.
        ini_set('date.timezone', 'Pacific/Galapagos');
        $this->assertEquals('Pacific/Galapagos', TimezoneUtil::getTimezone());
    }

    public function testGetTimezoneReturnsCurrent()
    {
        ini_set('date.timezone', 'Antarctica/McMurdo');
        date_default_timezone_set('Antarctica/Troll');
        $this->assertEquals('Antarctica/Troll', TimezoneUtil::getTimezone());
    }

    public function testGetTimezoneReturnsEnvVar()
    {
        @ini_set('date.timezone', '');
        putenv('TZ=Arctic/Longyearbyen');
        $this->assertEquals('Arctic/Longyearbyen', TimezoneUtil::getTimezone());
    }

    public function testGetTimezoneReturnsSomething()
    {
        $this->assertNotEmpty(TimezoneUtil::getTimezone());
    }

    public function testConvertTz()
    {
        $util = new TimezoneUtil();
        $method = new \ReflectionMethod($util,'convertTz');
        $method->setAccessible(true);
        $dataDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
        $cases = [
            'UTC' => 'UTC',
            'GMT' => 'GMT',
            'AEST' => 'AEST',
            'UTC0' => 'UTC',
            'UTC+0' => 'UTC',
            'UTC-0' => 'UTC',
            'AEST+0' => 'AEST',
            'UTC+1' => false,
            'name' => 'name',
            'x' => false, // the name must be "three or more characters long"
            ":$dataDir/tz" => 'Antarctica/Troll',
            ":$dataDir/tz_" => false,
            ':' => false,
        ];
        foreach ($cases as $input => $expected) {
            $this->assertEquals($expected, $method->invoke($util, $input), "converting \"$input\"");
        }
    }
}
