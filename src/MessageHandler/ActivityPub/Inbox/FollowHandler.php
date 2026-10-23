<?php

declare(strict_types=1);

namespace App\MessageHandler\ActivityPub\Inbox;

use App\Entity\Magazine;
use App\Entity\MagazineFollow;
use App\Entity\User;
use App\Message\ActivityPub\Inbox\FollowMessage;
use App\Message\Contracts\MessageInterface;
use App\MessageHandler\MbinMessageHandler;
use App\Service\ActivityPub\ActivityJsonBuilder;
use App\Service\ActivityPub\ApHttpClientInterface;
use App\Service\ActivityPub\Wrapper\FollowResponseWrapper;
use App\Service\ActivityPubManager;
use App\Service\MagazineManager;
use App\Service\OutboundFederationPolicy;
use App\Service\UserManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class FollowHandler extends MbinMessageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly KernelInterface $kernel,
        private readonly ActivityPubManager $activityPubManager,
        private readonly UserManager $userManager,
        private readonly MagazineManager $magazineManager,
        private readonly ApHttpClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly FollowResponseWrapper $followResponseWrapper,
        private readonly OutboundFederationPolicy $policy,
        private readonly ActivityJsonBuilder $activityJsonBuilder,
    ) {
        parent::__construct($this->entityManager, $this->kernel);
    }

    public function __invoke(FollowMessage $message): void
    {
        $this->workWrapper($message);
    }

    public function doWork(MessageInterface $message): void
    {
        if (!($message instanceof FollowMessage)) {
            throw new \LogicException("FollowHandler called, but is wasn\'t a FollowMessage. Type: ".\get_class($message));
        }
        $this->logger->debug('got a FollowMessage: {message}', [$message]);
        $actor = $this->activityPubManager->findActorOrCreate($message->payload['actor']);
        // Check if actor is not empty
        if (!empty($actor)) {
            if ('Follow' === $message->payload['type']) {
                $object = $this->activityPubManager->findActorOrCreate($message->payload['object']);
                // Check if object is not empty
                if (!empty($object)) {
                    if ($this->policy->isReadOnlyInstance($message->payload['actor'])) {
                        // we read this instance but never send to it, so recording a follower
                        // there would be a subscription we silently never deliver. The Reject
                        // itself does reach them: handleFollowRequest posts directly rather
                        // than through DeliverManager, which is deliberate.
                        $this->handleFollowRequest($message->payload, $object, isReject: true);

                        return;
                    }

                    // FORK DIVERGENCE — do not offer this upstream: private magazines
                    // a restricted magazine has no remote followers, so it answers exactly as random does
                    if ($object instanceof Magazine and null === $object->apId and ('random' === $object->name || $object->isRestricted())) {
                        $this->handleFollowRequest($message->payload, $object, isReject: true);
                    } else {
                        $this->handleFollow($object, $actor);

                        // @todo manually Accept
                        $this->handleFollowRequest($message->payload, $object);
                    }
                }

                return;
            }

            if (isset($message->payload['object'])) {
                switch ($message->payload['type']) {
                    case 'Undo':
                        $this->handleUnfollow(
                            $actor,
                            $this->activityPubManager->findActorOrCreate($message->payload['object']['object'])
                        );
                        break;
                    case 'Accept':
                        if ($actor instanceof User) {
                            $this->handleAccept(
                                $actor,
                                $this->activityPubManager->findActorOrCreate($message->payload['object']['actor'])
                            );
                        }
                        break;
                    case 'Reject':
                        $this->handleReject(
                            $actor,
                            $this->activityPubManager->findActorOrCreate($message->payload['object']['actor'])
                        );
                        break;
                    default:
                        break;
                }
            }
        }
    }

    private function handleFollow(User|Magazine $object, User $actor): void
    {
        match (true) {
            $object instanceof User => $this->userManager->follow($actor, $object),
            $object instanceof Magazine => $this->magazineManager->subscribe($object, $actor),
            default => throw new \LogicException(),
        };
    }

    private function handleFollowRequest(array $payload, User|Magazine $object, bool $isReject = false): void
    {
        $activity = $this->followResponseWrapper->build($object, $payload, $isReject);
        $response = $this->activityJsonBuilder->buildActivityJson($activity);
        $this->client->post($this->client->getInboxUrl($payload['actor']), $object, $response);
    }

    private function handleUnfollow(User $actor, User|Magazine|null $object): void
    {
        if (!empty($object)) {
            match (true) {
                $object instanceof User => $this->userManager->unfollow($actor, $object),
                $object instanceof Magazine => $this->magazineManager->unsubscribe($object, $actor),
                default => throw new \LogicException(),
            };
        }
    }

    private function handleAccept(User $actor, User|Magazine|null $object): void
    {
        if (!empty($object)) {
            if ($object instanceof User) {
                $this->userManager->acceptFollow($object, $actor);
            }

            if ($object instanceof Magazine) {
                // $object is our own magazine, the follower. $actor is the remote
                // actor it followed, who just accepted.
                $this->updateMagazineFollowStatus($object, $actor, MagazineFollow::STATUS_ACCEPTED);
            }
        }
    }

    private function handleReject(User|Magazine $actor, User|Magazine|null $object): void
    {
        if (!empty($object)) {
            if ($actor instanceof Magazine) {
                // the rejecting actor is in "actor" and the original follower is in "object.actor", so a magazine
                // rejecting a follow means the subscription that was created optimistically has to be undone
                if ($object instanceof User) {
                    $this->magazineManager->unsubscribe($actor, $object);
                }

                return;
            }

            match (true) {
                $object instanceof User => $this->userManager->rejectFollow($object, $actor),
                // $object is our own magazine, the follower, not a local subscriber
                // to unsubscribe. $actor is the remote actor that rejected the follow.
                $object instanceof Magazine => $this->updateMagazineFollowStatus($object, $actor, MagazineFollow::STATUS_REJECTED),
                default => throw new \LogicException(),
            };
        }
    }

    /**
     * Records whether a remote actor accepted or rejected our magazine's Follow
     * of it, so the moderator-facing status is not left permanently pending.
     */
    private function updateMagazineFollowStatus(Magazine $magazine, User|Magazine $followedActor, string $status): void
    {
        $magazineFollow = $this->entityManager->getRepository(MagazineFollow::class)->findOneBy(
            $followedActor instanceof User
                ? ['magazine' => $magazine, 'followingUser' => $followedActor]
                : ['magazine' => $magazine, 'followingMagazine' => $followedActor]
        );

        if (null !== $magazineFollow) {
            $magazineFollow->status = $status;
            $this->entityManager->flush();
        }
    }
}
