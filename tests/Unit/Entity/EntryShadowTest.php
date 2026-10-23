<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Entry;
use App\Entity\Magazine;
use App\Entity\Post;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class EntryShadowTest extends TestCase
{
    private function entry(Magazine $magazine, User $user): Entry
    {
        return new Entry('title', null, 'body', $magazine, $user, false, false, 'en');
    }

    public function testEntryIsShadowWhenEitherOriginSet(): void
    {
        $user = new User('a@example.com', 'a', 'secret', User::USER_TYPE_PERSON);
        $magazine = new Magazine('mag', 'Mag', $user, null, null, false, false, null);

        $plain = $this->entry($magazine, $user);
        $this->assertFalse($plain->isShadow());

        $original = $this->entry($magazine, $user);
        $viaEntry = $this->entry($magazine, $user);
        $viaEntry->shadowOfEntry = $original;
        $this->assertTrue($viaEntry->isShadow());

        $viaPost = $this->entry($magazine, $user);
        $viaPost->shadowOfPost = (new \ReflectionClass(Post::class))->newInstanceWithoutConstructor();
        $this->assertTrue($viaPost->isShadow());
    }
}
