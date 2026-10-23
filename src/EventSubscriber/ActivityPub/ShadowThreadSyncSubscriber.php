<?php

declare(strict_types=1);

namespace App\EventSubscriber\ActivityPub;

use App\Entity\Entry;
use App\Entity\Post;
use App\Entity\User;
use App\Event\Entry\EntryBeforePurgeEvent;
use App\Event\Entry\EntryDeletedEvent;
use App\Event\Entry\EntryRestoredEvent;
use App\Event\Post\PostBeforePurgeEvent;
use App\Event\Post\PostDeletedEvent;
use App\Event\Post\PostRestoredEvent;
use App\Service\ActivityPub\ShadowThreadManager;
use App\Service\EntryManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Carries the deletion and the restoration of a remote post onto its shadow threads.
 * Before the original is purged, a shadow with comments is soft deleted and kept, so that
 * the members' discussion survives, and a shadow without comments is purged the way any
 * thread is, so that the ORM removes its notifications, badges and images.
 *
 * FORK DIVERGENCE — do not offer this upstream: private magazines
 */
class ShadowThreadSyncSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly ShadowThreadManager $shadowThreadManager,
        private readonly EntryManager $entryManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EntryDeletedEvent::class => 'onEntryChanged',
            EntryRestoredEvent::class => 'onEntryChanged',
            PostDeletedEvent::class => 'onPostChanged',
            PostRestoredEvent::class => 'onPostChanged',
            EntryBeforePurgeEvent::class => 'onEntryBeforePurge',
            PostBeforePurgeEvent::class => 'onPostBeforePurge',
        ];
    }

    public function onEntryChanged(EntryDeletedEvent|EntryRestoredEvent $event): void
    {
        if ($this->mayHaveShadows($event->entry)) {
            $this->shadowThreadManager->sync($event->entry);
        }
    }

    public function onPostChanged(PostDeletedEvent|PostRestoredEvent $event): void
    {
        if ($this->mayHaveShadows($event->post)) {
            $this->shadowThreadManager->sync($event->post);
        }
    }

    public function onEntryBeforePurge(EntryBeforePurgeEvent $event): void
    {
        if ($this->mayHaveShadows($event->entry)) {
            $this->releaseShadows($event->entry, $event->user);
        }
    }

    public function onPostBeforePurge(PostBeforePurgeEvent $event): void
    {
        if ($this->mayHaveShadows($event->post)) {
            $this->releaseShadows($event->post, $event->user);
        }
    }

    private function releaseShadows(Entry|Post $original, User $user): void
    {
        foreach ($this->shadowThreadManager->detachDiscussedShadows($original) as $shadow) {
            $this->entryManager->purge($user, $shadow);
            $this->shadowThreadManager->recount([$shadow]);
        }
    }

    /**
     * Only a remote post is shadowed, and a shadow is never shadowed itself.
     */
    private function mayHaveShadows(Entry|Post $subject): bool
    {
        return null !== $subject->apId && !($subject instanceof Entry && $subject->isShadow());
    }
}
