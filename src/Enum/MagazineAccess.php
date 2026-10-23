<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Who may see a magazine and what is in it. Set once by an admin when the
 * magazine is created and never changed afterwards.
 *
 * FORK DIVERGENCE — do not offer this upstream: private magazines
 */
enum MagazineAccess: string
{
    case Public = 'public';
    case Private = 'private';
    case Hidden = 'hidden';

    public function isRestricted(): bool
    {
        return self::Public !== $this;
    }
}
