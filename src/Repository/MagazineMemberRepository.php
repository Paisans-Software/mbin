<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Magazine;
use App\Entity\MagazineMember;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Collections\Criteria;
use Doctrine\Persistence\ManagerRegistry;
use Pagerfanta\Doctrine\Collections\SelectableAdapter;
use Pagerfanta\Exception\NotValidCurrentPageException;
use Pagerfanta\Pagerfanta;
use Pagerfanta\PagerfantaInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 *
 * @extends ServiceEntityRepository<MagazineMember>
 *
 * @method MagazineMember|null find($id, $lockMode = null, $lockVersion = null)
 * @method MagazineMember|null findOneBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null)
 * @method MagazineMember[]    findAll()
 * @method MagazineMember[]    findBy(array<string, mixed> $criteria, array<string, string>|null $orderBy = null, $limit = null, $offset = null)
 */
class MagazineMemberRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 50;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MagazineMember::class);
    }

    /**
     * @return PagerfantaInterface<MagazineMember>
     */
    public function findMembers(Magazine $magazine, int $page, int $perPage = self::PER_PAGE): PagerfantaInterface
    {
        $criteria = Criteria::create()->orderBy(['createdAt' => 'DESC']);

        $members = new Pagerfanta(new SelectableAdapter($magazine->members, $criteria));
        try {
            $members->setMaxPerPage($perPage);
            $members->setCurrentPage($page);
        } catch (NotValidCurrentPageException) {
            throw new NotFoundHttpException();
        }

        return $members;
    }

    public function findOneByMagazineAndUser(Magazine $magazine, User $user): ?MagazineMember
    {
        return $this->findOneBy(['magazine' => $magazine, 'user' => $user]);
    }
}
