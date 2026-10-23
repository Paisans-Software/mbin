<?php

declare(strict_types=1);

namespace App\MessageHandler\Notification;

use App\Message\Contracts\MessageInterface;
use App\Message\Notification\MagazineAccessRequestedNotificationMessage;
use App\MessageHandler\MbinMessageHandler;
use App\Repository\MagazineAccessRequestRepository;
use App\Service\NotificationManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
#[AsMessageHandler]
class SentMagazineAccessRequestedNotificationHandler extends MbinMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly KernelInterface $kernel,
        private readonly MagazineAccessRequestRepository $repository,
        private readonly NotificationManager $notificationManager,
    ) {
        parent::__construct($this->entityManager, $this->kernel);
    }

    public function __invoke(MagazineAccessRequestedNotificationMessage $message): void
    {
        $this->workWrapper($message);
    }

    public function doWork(MessageInterface $message): void
    {
        if (!($message instanceof MagazineAccessRequestedNotificationMessage)) {
            throw new \LogicException();
        }
        $request = $this->repository->find($message->requestId);

        if (!$request) {
            // already decided before the message was handled: nobody needs to hear about it
            throw new UnrecoverableMessageHandlingException('Access request not found');
        }

        $this->notificationManager->sendMagazineAccessRequestedNotification($request);
    }
}
