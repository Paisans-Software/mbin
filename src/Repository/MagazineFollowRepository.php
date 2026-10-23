<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Magazine;
use App\Entity\MagazineFollow;
use App\Entity\User;
use App\Enum\MagazineAccess;
use App\Enum\MagazineFollowKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MagazineFollow>
 */
class MagazineFollowRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MagazineFollow::class);
    }

    /**
     * The magazine, if any, that follows the actor at this URL.
     *
     * Both actor tables are checked because Mbin stores a Group actor as a
     * Magazine and every other actor type as a User.
     */
    public function findMagazineFollowing(?string $actorUrl, ?string $activityType = null): ?Magazine
    {
        if (null === $actorUrl) {
            return null;
        }

        // Nothing stops two magazines from following the same actor, since the
        // unique indexes are scoped per magazine. When that happens, the first
        // magazine to have claimed the actor wins, so the ordering below must
        // be stable.
        $qb = $this->createQueryBuilder('mf')
            ->leftJoin('mf.followingUser', 'u')
            ->leftJoin('mf.followingMagazine', 'm')
            ->where('u.apProfileId = :url OR m.apProfileId = :url')
            ->setParameter('url', $actorUrl)
            // FORK DIVERGENCE — do not offer this upstream: private magazines
            // a restricted magazine takes in no federated content, so its follows never
            // route anything to it and the object takes the normal unrouted path instead
            ->join('mf.magazine', 'fm')
            ->andWhere('fm.access = :public')
            ->setParameter('public', MagazineAccess::Public);

        if (null !== $activityType) {
            // A follow that does not carry this kind does not answer, and the object then
            // takes the normal unrouted path. Filing it under the magazine anyway would
            // deliver exactly what its moderator asked not to receive.
            $qb->andWhere('mf.kind IN (:kinds)')
                ->setParameter('kinds', array_values(array_filter(
                    MagazineFollowKind::cases(),
                    fn (MagazineFollowKind $kind) => $kind->carries($activityType)
                )));
        }

        $result = $qb
            ->orderBy('mf.createdAt', 'ASC')
            ->addOrderBy('mf.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result?->magazine;
    }

    /**
     * Every restricted local magazine that follows one of these actors with a follow
     * carrying this activity kind. Such a magazine files nothing itself and gets a
     * shadow of the object instead.
     *
     * FORK DIVERGENCE — do not offer this upstream: private magazines
     *
     * @param array<?string> $actorUrls
     *
     * @return Magazine[]
     */
    public function findRestrictedMagazinesFollowing(array $actorUrls, string $activityType): array
    {
        $actorUrls = array_values(array_unique(array_filter($actorUrls, fn (?string $url) => null !== $url && '' !== $url)));
        $kinds = array_values(array_filter(
            MagazineFollowKind::cases(),
            fn (MagazineFollowKind $kind) => $kind->carries($activityType)
        ));
        if ([] === $actorUrls || [] === $kinds) {
            return [];
        }

        $restricted = array_values(array_filter(
            MagazineAccess::cases(),
            fn (MagazineAccess $access) => $access->isRestricted()
        ));

        // most instances have no restricted magazine that follows anybody, and this runs for
        // every inbound post, so a cheap probe settles that case before the real query
        $anyRestrictedFollow = $this->createQueryBuilder('mf')
            ->select('mf.id')
            ->join('mf.magazine', 'fm')
            ->where('fm.apId IS NULL')
            ->andWhere('fm.access IN (:restricted)')
            ->setParameter('restricted', $restricted)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if (null === $anyRestrictedFollow) {
            return [];
        }

        // one indexed IN per actor table rather than an OR across two left joins
        $em = $this->getEntityManager();
        $userIds = $em->createQueryBuilder()
            ->select('u.id')
            ->from(User::class, 'u')
            ->where('u.apProfileId IN (:urls)');
        $magazineIds = $em->createQueryBuilder()
            ->select('m.id')
            ->from(Magazine::class, 'm')
            ->where('m.apProfileId IN (:urls)');

        $follows = $this->createQueryBuilder('mf')
            ->join('mf.magazine', 'fm')
            ->addSelect('fm')
            ->where('IDENTITY(mf.followingUser) IN ('.$userIds->getDQL().') OR IDENTITY(mf.followingMagazine) IN ('.$magazineIds->getDQL().')')
            ->andWhere('fm.apId IS NULL')
            ->andWhere('fm.access IN (:restricted)')
            ->andWhere('mf.kind IN (:kinds)')
            ->setParameter('urls', $actorUrls)
            ->setParameter('restricted', $restricted)
            ->setParameter('kinds', $kinds)
            ->orderBy('fm.id', 'ASC')
            ->getQuery()
            ->getResult();

        $magazines = [];
        foreach ($follows as $follow) {
            $magazines[$follow->magazine->getId()] = $follow->magazine;
        }

        return array_values($magazines);
    }

    /**
     * @return MagazineFollow[]
     */
    public function findByMagazine(Magazine $magazine): array
    {
        return $this->findBy(['magazine' => $magazine], ['createdAt' => 'DESC']);
    }

    /**
     * Whether the magazine follows anybody at all, regardless of status.
     *
     * FORK DIVERGENCE — do not offer this upstream: private magazines
     */
    public function magazineHasFollows(Magazine $magazine): bool
    {
        return null !== $this->createQueryBuilder('mf')
            ->select('mf.id')
            ->where('mf.magazine = :magazine')
            ->setParameter('magazine', $magazine)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Whether the magazine already follows this actor, regardless of status.
     */
    public function findOneByMagazineAndActor(Magazine $magazine, User|Magazine $actor): ?MagazineFollow
    {
        return $this->findOneBy(
            $actor instanceof User
                ? ['magazine' => $magazine, 'followingUser' => $actor]
                : ['magazine' => $magazine, 'followingMagazine' => $actor]
        );
    }
}
