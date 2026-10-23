<?php

declare(strict_types=1);

namespace App\Message\Notification;

use App\Message\Contracts\AsyncMessageInterface;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineAccessRequestedNotificationMessage implements AsyncMessageInterface
{
    public function __construct(public int $requestId)
    {
    }
}
