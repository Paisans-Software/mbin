<?php

declare(strict_types=1);

namespace App\MessageHandler\Notification;

use App\Message\Contracts\MessageInterface;
use App\Message\Notification\MagazineAccessGrantedNotificationMessage;
use App\MessageHandler\MbinMessageHandler;
use App\Repository\MagazineRepository;
use App\Repository\UserRepository;
use App\Service\NotificationManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
#[AsMessageHandler]
class SentMagazineAccessGrantedNotificationHandler extends MbinMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly KernelInterface $kernel,
        private readonly MagazineRepository $magazineRepository,
        private readonly UserRepository $userRepository,
        private readonly NotificationManager $notificationManager,
    ) {
        parent::__construct($this->entityManager, $this->kernel);
    }

    public function __invoke(MagazineAccessGrantedNotificationMessage $message): void
    {
        $this->workWrapper($message);
    }

    public function doWork(MessageInterface $message): void
    {
        if (!($message instanceof MagazineAccessGrantedNotificationMessage)) {
            throw new \LogicException();
        }
        $magazine = $this->magazineRepository->find($message->magazineId);
        $user = $this->userRepository->find($message->userId);

        if (!$magazine || !$user) {
            throw new UnrecoverableMessageHandlingException('Magazine or user not found');
        }

        $this->notificationManager->sendMagazineAccessGrantedNotification($magazine, $user);
    }
}
