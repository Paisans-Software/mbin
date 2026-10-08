<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Controller\HealthController;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns any failure on the health route into an empty 503, whether it was thrown by
 * the controller or by a listener that ran before it (several read the database).
 * It runs after the exception is logged and before the error page is rendered.
 */
class HealthExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', -32],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (HealthController::ROUTE !== $event->getRequest()->attributes->get('_route')) {
            return;
        }

        $event->setResponse(new Response('', Response::HTTP_SERVICE_UNAVAILABLE));
    }
}
