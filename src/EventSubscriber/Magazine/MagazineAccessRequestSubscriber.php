<?php

declare(strict_types=1);

namespace App\EventSubscriber\Magazine;

use App\Event\Magazine\MagazineAccessRequestedEvent;
use App\Message\Notification\MagazineAccessRequestedNotificationMessage;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineAccessRequestSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MagazineAccessRequestedEvent::class => 'onRequested',
        ];
    }

    public function onRequested(MagazineAccessRequestedEvent $event): void
    {
        $this->bus->dispatch(new MagazineAccessRequestedNotificationMessage($event->request->getId()));
    }
}
