<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\MagazineAccess;
use PHPUnit\Framework\TestCase;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineAccessTest extends TestCase
{
    public function testOnlyPrivateAndHiddenAreRestricted(): void
    {
        self::assertFalse(MagazineAccess::Public->isRestricted());
        self::assertTrue(MagazineAccess::Private->isRestricted());
        self::assertTrue(MagazineAccess::Hidden->isRestricted());
    }
}
