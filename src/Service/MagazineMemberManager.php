<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Magazine;
use App\Entity\MagazineAccessRequest;
use App\Entity\MagazineMember;
use App\Entity\User;
use App\Repository\MagazineMemberRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineMemberManager
{
    public function __construct(
        private readonly MagazineMemberRepository $repository,
        private readonly MagazineManager $magazineManager,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function add(Magazine $magazine, User $user, User $addedBy): MagazineMember
    {
        if (null !== $user->apId) {
            throw new \InvalidArgumentException('Only local users can be members of a magazine.');
        }
        if (!$magazine->isRestricted()) {
            throw new \InvalidArgumentException('Only private and hidden magazines have members.');
        }
        if ($user->isBanned || $user->isDeleted || $user->isSoftDeleted()) {
            throw new \InvalidArgumentException('Banned or deleted accounts cannot be members of a magazine.');
        }

        $existing = $this->repository->findOneByMagazineAndUser($magazine, $user);
        if (null !== $existing) {
            return $existing;
        }

        $member = new MagazineMember($magazine, $user, $addedBy);
        $magazine->members->add($member);
        $this->entityManager->persist($member);
        // FORK DIVERGENCE — do not offer this upstream: private magazines
        // Every admission path ends here, so a pending request never outlives membership.
        $this->entityManager->createQueryBuilder()
            ->delete(MagazineAccessRequest::class, 'r')
            ->where('r.magazine = :magazine AND r.user = :user')
            ->setParameter('magazine', $magazine)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
        $this->entityManager->flush();

        return $member;
    }

    public function remove(Magazine $magazine, User $user): void
    {
        $member = $this->repository->findOneByMagazineAndUser($magazine, $user);
        if (null !== $member) {
            $magazine->members->removeElement($member);
            $this->entityManager->remove($member);
            $this->entityManager->flush();
        }

        if ($magazine->isSubscribed($user)) {
            $this->magazineManager->unsubscribe($magazine, $user);
        }
    }
}
