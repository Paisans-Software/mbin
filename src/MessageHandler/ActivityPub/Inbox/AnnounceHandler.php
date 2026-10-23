<?php

declare(strict_types=1);

namespace App\MessageHandler\ActivityPub\Inbox;

use App\Entity\Contracts\ContentInterface;
use App\Entity\User;
use App\EventSubscriber\VoteHandleSubscriber;
use App\Message\ActivityPub\Inbox\AnnounceMessage;
use App\Message\ActivityPub\Inbox\ChainActivityMessage;
use App\Message\Contracts\MessageInterface;
use App\MessageHandler\MbinMessageHandler;
use App\Service\ActivityPubManager;
use App\Service\VoteManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
class AnnounceHandler extends MbinMessageHandler
{
    public function __construct(
        private readonly ActivityPubManager $activityPubManager,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly KernelInterface $kernel,
        private readonly VoteManager $voteManager,
        private readonly VoteHandleSubscriber $voteHandleSubscriber,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct($this->entityManager, $this->kernel);
    }

    public function __invoke(AnnounceMessage $message): void
    {
        $this->workWrapper($message);
    }

    public function doWork(MessageInterface $message): void
    {
        if (!($message instanceof AnnounceMessage)) {
            throw new \LogicException("AnnounceHandler called, but is wasn\'t an AnnounceMessage. Type: ".\get_class($message));
        }
        $chainDispatchCallback = function (array $object, ?string $adjustedUrl) use ($message) {
            if ($adjustedUrl) {
                $this->logger->info('[AnnounceHandler::doWork] Got an adjusted url: {url}, using that instead of {old}', ['url' => $adjustedUrl, 'old' => $message->payload['object']['id'] ?? $message->payload['object']]);
                $message->payload['object'] = $adjustedUrl;
            }
            $this->bus->dispatch(new ChainActivityMessage([$object], announce: $message->payload, deliveredBy: $message->payload['actor'] ?? null, deliveredKind: 'Announce'));
        };

        if ('Announce' === $message->payload['type']) {
            $entity = $this->activityPubManager->getEntityObject($message->payload['object'], $message->payload, $chainDispatchCallback);
            if (!$entity) {
                return;
            }
            // FORK DIVERGENCE — do not offer this upstream: private magazines
            // a remote actor is never a viewer of a restricted magazine, so it may not vote on,
            // favourite or boost its content either; dropped before anything is stored
            if (self::isRestrictedContent($entity)) {
                return;
            }
            $actor = $this->activityPubManager->findActorOrCreate($message->payload['actor']);

            if ($actor instanceof User) {
                $this->voteManager->upvote($entity, $actor);
                $this->voteHandleSubscriber->clearCache($entity);
            } else {
                $entity->lastActive = new \DateTime();
                $this->entityManager->flush();
            }
        }

        if ('Undo' === $message->payload['type']) {
            return;
        }
    }

    /**
     * FORK DIVERGENCE — do not offer this upstream: private magazines.
     */
    private static function isRestrictedContent(mixed $entity): bool
    {
        return $entity instanceof ContentInterface && true === $entity->getMagazine()?->isRestricted();
    }
}
