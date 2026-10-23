<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Contracts\ContentInterface;
use App\Entity\Magazine;
use App\Entity\MagazineAccessRequest;
use App\Entity\MagazineBan;
use App\Entity\Message;
use App\Entity\MessageNotification;
use App\Entity\Notification;
use App\Entity\User;
use App\Service\Notification\MagazineAccessNotificationManager;
use App\Service\Notification\MagazineBanNotificationManager;
use App\Service\Notification\MessageNotificationManager;
use Doctrine\ORM\EntityManagerInterface;

class NotificationManager
{
    public function __construct(
        private readonly NotificationManagerTypeResolver $resolver,
        private readonly MessageNotificationManager $messageNotificationManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly MagazineBanNotificationManager $magazineBanNotificationManager,
        // FORK DIVERGENCE — do not offer this upstream: private magazines
        private readonly MagazineAccessNotificationManager $magazineAccessNotificationManager,
    ) {
    }

    public function sendCreated(ContentInterface $subject): void
    {
        $this->resolver->resolve($subject)->sendCreated($subject);
    }

    public function sendEdited(ContentInterface $subject): void
    {
        $this->resolver->resolve($subject)->sendEdited($subject);
    }

    public function sendDeleted(ContentInterface $subject): void
    {
        $this->resolver->resolve($subject)->sendDeleted($subject);
    }

    public function sendMessageNotification(Message $message, User $sender): void
    {
        $this->messageNotificationManager->send($message, $sender);
    }

    public function sendMagazineBanNotification(MagazineBan $ban): void
    {
        $this->magazineBanNotificationManager->send($ban);
    }

    // FORK DIVERGENCE — do not offer this upstream: private magazines
    public function sendMagazineAccessRequestedNotification(MagazineAccessRequest $request): void
    {
        $this->magazineAccessNotificationManager->sendRequested($request);
    }

    // FORK DIVERGENCE — do not offer this upstream: private magazines
    public function sendMagazineAccessGrantedNotification(Magazine $magazine, User $user): void
    {
        $this->magazineAccessNotificationManager->sendGranted($magazine, $user);
    }

    public function markAllAsRead(User $user): void
    {
        $notifications = $user->getNewNotifications();

        foreach ($notifications as $notification) {
            $notification->status = Notification::STATUS_READ;
        }

        $this->entityManager->flush();
    }

    public function clear(User $user): void
    {
        $notifications = $user->notifications;

        foreach ($notifications as $notification) {
            $this->entityManager->remove($notification);
        }

        $this->entityManager->flush();
    }

    public function readMessageNotification(Message $message, User $user): void
    {
        $repo = $this->entityManager->getRepository(MessageNotification::class);

        $notifications = $repo->findBy(
            [
                'message' => $message,
                'user' => $user,
            ]
        );

        foreach ($notifications as $notification) {
            $notification->status = Notification::STATUS_READ;
        }

        $this->entityManager->flush();
    }

    public function unreadMessageNotification(Message $message, User $user): void
    {
        $repo = $this->entityManager->getRepository(MessageNotification::class);

        $notifications = $repo->findBy(
            [
                'message' => $message,
                'user' => $user,
            ]
        );

        foreach ($notifications as $notification) {
            $notification->status = Notification::STATUS_NEW;
        }

        $this->entityManager->flush();
    }
}
