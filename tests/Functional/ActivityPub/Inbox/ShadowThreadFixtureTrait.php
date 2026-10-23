<?php

declare(strict_types=1);

namespace App\Tests\Functional\ActivityPub\Inbox;

use App\Entity\Contracts\ActivityPubActivityInterface;
use App\Entity\Entry;
use App\Entity\Magazine;
use App\Entity\MagazineFollow;
use App\Enum\MagazineAccess;
use App\Enum\MagazineFollowKind;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 *
 * Fixtures shared by the shadow thread tests: a private magazine 'circle', a hidden
 * 'ring' and public 'garden' and 'random', and remote Create activities addressed to
 * nobody so that their originals are filed in 'random'.
 */
trait ShadowThreadFixtureTrait
{
    /** The entry behind the page most recently built by remotePageCreate(). */
    protected ?Entry $lastRemotePage = null;

    public function setUpLocalEntities(): void
    {
        $admin = $this->getUserByUsername('admin', isAdmin: true);
        $circle = $this->getMagazineByName('circle', $admin);
        $circle->access = MagazineAccess::Private;
        $ring = $this->getMagazineByName('ring', $admin);
        $ring->access = MagazineAccess::Hidden;
        $this->getMagazineByName('garden', $admin);
        $this->getMagazineByName('random', $admin);
        $this->entityManager->flush();
    }

    protected function follow(Magazine $magazine, MagazineFollowKind $kind): void
    {
        $follow = new MagazineFollow($magazine, $this->remoteUser);
        $follow->status = MagazineFollow::STATUS_ACCEPTED;
        $follow->kind = $kind;
        $this->entityManager->persist($follow);
        $this->entityManager->flush();
    }

    /**
     * @param array<string, mixed> $create
     *
     * @return array<string, mixed>
     */
    protected function addressedToNobody(array $create): array
    {
        foreach ([&$create, &$create['object']] as &$activity) {
            unset($activity['audience']);
            $activity['to'] = [ActivityPubActivityInterface::PUBLIC_URL];
            $activity['cc'] = [];
        }
        unset($activity);

        return $create;
    }

    /**
     * @return array<string, mixed>
     */
    protected function remotePageCreate(string $title): array
    {
        $entry = $this->getEntryByTitle($title, magazine: $this->remoteMagazine, user: $this->remoteUser);
        $createActivity = $this->createWrapper->build($entry);
        $create = $this->activityJsonBuilder->buildActivityJson($createActivity);
        $create['cc'] = [];
        $create['object']['cc'] = [];

        $this->entitiesToRemoveAfterSetup[] = $createActivity;
        $this->entitiesToRemoveAfterSetup[] = $entry;
        $this->lastRemotePage = $entry;

        return $create;
    }

    /**
     * @return array<string, mixed>
     */
    protected function remoteNoteCreate(string $body): array
    {
        $post = $this->createPost($body, magazine: $this->remoteMagazine, user: $this->remoteUser);
        $createActivity = $this->createWrapper->build($post);
        $create = $this->activityJsonBuilder->buildActivityJson($createActivity);
        $create['cc'] = [];
        $create['object']['cc'] = [];

        $this->entitiesToRemoveAfterSetup[] = $createActivity;
        $this->entitiesToRemoveAfterSetup[] = $post;

        return $create;
    }
}
