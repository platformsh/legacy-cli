<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests\Local\BuildFlavor;

use PHPUnit\Framework\Attributes\Group;
use Platformsh\Cli\Exception\InvalidConfigException;

#[Group('slow')]
class InvalidAppTest extends BuildFlavorTestBase
{
    public function testNoAppConfigThrowsException(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('Configuration file not found');
        $this->assertBuildSucceeds('tests/data/apps/invalid', [], false);
    }
}
