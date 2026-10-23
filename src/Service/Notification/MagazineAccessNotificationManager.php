<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\Magazine;
use App\Entity\MagazineAccessGrantedNotification;
use App\Entity\MagazineAccessRequest;
use App\Entity\MagazineAccessRequestedNotification;
use App\Entity\User;
use App\Event\NotificationCreatedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineAccessNotificationManager
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Tells every local moderator of the magazine, the owner included.
     */
    public function sendRequested(MagazineAccessRequest $request): void
    {
        $magazine = $request->magazine;
        $requester = $request->user;

        $notifications = [];
        foreach ($magazine->moderators as $moderator) {
            if (null !== $moderator->user->apId || (!$moderator->isConfirmed && !$moderator->isOwner)) {
                continue;
            }
            $notification = new MagazineAccessRequestedNotification($moderator->user, $magazine, $requester);
            $this->entityManager->persist($notification);
            $notifications[] = $notification;
        }
        $this->entityManager->flush();

        foreach ($notifications as $notification) {
            $this->eventDispatcher->dispatch(new NotificationCreatedEvent($notification));
        }
    }

    public function sendGranted(Magazine $magazine, User $user): void
    {
        $notification = new MagazineAccessGrantedNotification($user, $magazine);

        $this->entityManager->persist($notification);
        $this->entityManager->flush();
        $this->eventDispatcher->dispatch(new NotificationCreatedEvent($notification));
    }
}
