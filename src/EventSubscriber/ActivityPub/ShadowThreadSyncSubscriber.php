<?php

declare(strict_types=1);

namespace App\EventSubscriber\ActivityPub;

use App\Event\Entry\EntryDeletedEvent;
use App\Event\Entry\EntryRestoredEvent;
use App\Event\Post\PostDeletedEvent;
use App\Event\Post\PostRestoredEvent;
use App\Service\ActivityPub\ShadowThreadManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Carries the deletion and the restoration of a remote post onto its shadow threads.
 * A purge needs nothing here: the shadows go with the original through the foreign keys.
 *
 * FORK DIVERGENCE — do not offer this upstream: private magazines
 */
class ShadowThreadSyncSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ShadowThreadManager $shadowThreadManager)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EntryDeletedEvent::class => 'onEntryChanged',
            EntryRestoredEvent::class => 'onEntryChanged',
            PostDeletedEvent::class => 'onPostChanged',
            PostRestoredEvent::class => 'onPostChanged',
        ];
    }

    public function onEntryChanged(EntryDeletedEvent|EntryRestoredEvent $event): void
    {
        if (null !== $event->entry->apId) {
            $this->shadowThreadManager->sync($event->entry);
        }
    }

    public function onPostChanged(PostDeletedEvent|PostRestoredEvent $event): void
    {
        if (null !== $event->post->apId) {
            $this->shadowThreadManager->sync($event->post);
        }
    }
}
