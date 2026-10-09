<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Settings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Settings>
 *
 * @method Settings|null find($id, $lockMode = null, $lockVersion = null)
 * @method Settings|null findOneBy(array $criteria, array $orderBy = null)
 * @method Settings[]    findAll()
 * @method Settings[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class SettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Settings::class);
    }

    /**
     * Every row, keyed by name, read from the database.
     *
     * The finders inherited from the repository go through Doctrine's second-level
     * query cache, which can name rows this database does not hold: the cache may be
     * shared by several databases, or keep rows from a transaction that was rolled
     * back. A DQL query is not cached unless it asks to be.
     *
     * @return array<string, Settings>
     */
    public function findAllIndexedByName(): array
    {
        return $this->createQueryBuilder('s', 's.name')->getQuery()->getResult();
    }
}
