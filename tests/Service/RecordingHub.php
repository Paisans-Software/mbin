<?php

declare(strict_types=1);

namespace App\Tests\Service;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 *
 * Test double for the Mercure hub that records every published update.
 */
class RecordingHub implements HubInterface
{
    /** @var Update[] */
    public array $updates = [];

    public function getPublicUrl(): string
    {
        return 'http://localhost/.well-known/mercure';
    }

    public function getFactory(): ?TokenFactoryInterface
    {
        return null;
    }

    public function publish(Update $update): string
    {
        $this->updates[] = $update;

        return 'recorded';
    }
}
