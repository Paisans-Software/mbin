<?php

declare(strict_types=1);

namespace App\Event\Magazine;

use App\Entity\MagazineAccessRequest;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineAccessRequestedEvent
{
    public function __construct(public MagazineAccessRequest $request)
    {
    }
}
