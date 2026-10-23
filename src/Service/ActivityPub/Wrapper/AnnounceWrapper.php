<?php

declare(strict_types=1);

namespace App\Service\ActivityPub\Wrapper;

use App\Entity\Activity;
use App\Entity\Contracts\ActivityPubActivityInterface;
use App\Entity\Entry;
use App\Entity\EntryComment;
use App\Entity\Magazine;
use App\Entity\Post;
use App\Entity\PostComment;
use App\Entity\User;
use App\Factory\ActivityPub\ActivityFactory;
use Doctrine\ORM\EntityManagerInterface;

class AnnounceWrapper
{
    public function __construct(
        private readonly ActivityFactory $activityFactory,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param User|Magazine                                $actor      the actor doing the announce
     * @param ActivityPubActivityInterface|Activity|string $object     the thing the actor is announcing.
     *                                                                 If it is a string it will be treated as a url to the activity this is announcing
     * @param bool                                         $idAsObject use only the id of $object as the 'object' in the payload.
     *                                                                 This should only be true for user boosts
     *
     * @return Activity an announce activity
     */
    public function build(User|Magazine $actor, ActivityPubActivityInterface|Activity|string $object, bool $idAsObject = false): Activity
    {
        $activity = new Activity('Announce');
        $activity->setActor($actor);
        if ($object instanceof Activity) {
            $activity->innerActivity = $object;
        } elseif ($object instanceof ActivityPubActivityInterface) {
            if ($idAsObject) {
                $arr = $this->activityFactory->create($object);
                $activity->setObject($arr['id']);
            } else {
                $activity->setObject($object);
            }
        } else {
            $url = filter_var($object, FILTER_VALIDATE_URL);
            if (false === $url) {
                throw new \LogicException('expecting the object to be an url if it is a string');
            }
            $activity->innerActivityUrl = $url;
        }

        // FORK DIVERGENCE — do not offer this upstream: private magazines
        // A user boost stores only the object's id, so record which magazine the announced
        // content is in: the public user outbox filters on it. The Announce JSON does not
        // read the audience, so the federated payload is unchanged.
        $activity->audience = self::magazineOf($object);

        $this->entityManager->persist($activity);
        $this->entityManager->flush();

        return $activity;
    }

    /**
     * The magazine the announced object belongs to. An object held only as a URL string
     * resolves to null: a URL only arrives here when a magazine announces a remote activity
     * it holds by URL alone (GenericAnnounceHandler), which has no local magazine to record.
     *
     * FORK DIVERGENCE — do not offer this upstream: private magazines
     */
    private static function magazineOf(ActivityPubActivityInterface|Activity|string $object): ?Magazine
    {
        if ($object instanceof Activity) {
            $object = $object->audience ?? $object->getObject();
        }
        if ($object instanceof Magazine) {
            return $object;
        }
        if ($object instanceof Entry || $object instanceof EntryComment || $object instanceof Post || $object instanceof PostComment) {
            return $object->magazine;
        }

        return null;
    }
}
