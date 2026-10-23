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
