<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Magazine;
use App\Entity\MagazineAccessRequest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Exception\NotValidCurrentPageException;
use Pagerfanta\Pagerfanta;
use Pagerfanta\PagerfantaInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 *
 * @extends ServiceEntityRepository<MagazineAccessRequest>
 *
 * @method MagazineAccessRequest|null find($id, $lockMode = null, $lockVersion = null)
 * @method MagazineAccessRequest|null findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method MagazineAccessRequest[]    findAll()
 * @method MagazineAccessRequest[]    findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, $limit = null, $offset = null)
 */
class MagazineAccessRequestRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MagazineAccessRequest::class);
    }

    /**
     * @return PagerfantaInterface<MagazineAccessRequest>
     */
    public function findPending(Magazine $magazine, int $page, int $perPage = self::PER_PAGE): PagerfantaInterface
    {
        $query = $this->createQueryBuilder('r')
            ->where('r.magazine = :magazine')
            ->setParameter('magazine', $magazine)
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery();

        $requests = new Pagerfanta(new QueryAdapter($query));
        try {
            $requests->setMaxPerPage($perPage);
            $requests->setCurrentPage($page);
        } catch (NotValidCurrentPageException) {
            throw new NotFoundHttpException();
        }

        return $requests;
    }

    public function findOneByMagazineAndUser(Magazine $magazine, User $user): ?MagazineAccessRequest
    {
        return $this->findOneBy(['magazine' => $magazine, 'user' => $user]);
    }
}
