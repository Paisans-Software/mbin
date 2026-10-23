<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Entity\Magazine;
use App\Entity\User;
use App\Repository\MagazineAccessClause;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Parameter;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineAccessClauseTest extends TestCase
{
    public function testNullViewerAllowsOnlyPublic(): void
    {
        $qb = $this->builder();
        MagazineAccessClause::apply($qb, 'm', null);

        $dql = $qb->getDQL();
        self::assertStringContainsString("m.access = 'public'", $dql);
        self::assertStringNotContainsString('EXISTS', $dql);
        self::assertCount(0, $qb->getParameters());
    }

    public function testAdminAddsNothing(): void
    {
        foreach ([$this->viewer(admin: true), $this->viewer(globalModerator: true)] as $viewer) {
            $qb = $this->builder();
            $before = $qb->getDQL();
            MagazineAccessClause::apply($qb, 'm', $viewer);

            self::assertSame($before, $qb->getDQL());
            self::assertCount(0, $qb->getParameters());
            self::assertSame(['TRUE', []], MagazineAccessClause::sql('m', $viewer));
        }
    }

    public function testUserClauseUsesMembersBansAndModerators(): void
    {
        $qb = $this->builder();
        MagazineAccessClause::apply($qb, 'm', $this->viewer());

        $dql = $qb->getDQL();
        self::assertStringContainsString("m.access = 'public'", $dql);
        self::assertStringContainsString('App\Entity\MagazineMember', $dql);
        self::assertStringContainsString('App\Entity\MagazineBan', $dql);
        self::assertStringContainsString('App\Entity\Moderator', $dql);
        self::assertStringContainsString('CURRENT_TIMESTAMP()', $dql);
        self::assertStringContainsString(':mac_viewer_m', $dql);
        self::assertSame(42, $qb->getParameter('mac_viewer_m')?->getValue());

        [$sql, $params] = MagazineAccessClause::sql('m', $this->viewer());
        self::assertStringContainsString("m.access = 'public'", $sql);
        self::assertStringContainsString('magazine_member', $sql);
        self::assertStringContainsString('magazine_ban', $sql);
        self::assertStringContainsString('moderator', $sql);
        self::assertSame(['mac_viewer_m' => 42], $params);
    }

    public function testNullViewerSqlAllowsOnlyPublic(): void
    {
        self::assertSame(["m.access = 'public'", []], MagazineAccessClause::sql('m', null));
    }

    public function testTwoAliasesDoNotCollide(): void
    {
        $qb = $this->builder();
        $qb->join('m.moderators', 'm2');
        MagazineAccessClause::apply($qb, 'm', $this->viewer());
        MagazineAccessClause::apply($qb, 'm2', $this->viewer());

        $names = array_map(fn (Parameter $p) => $p->getName(), $qb->getParameters()->toArray());
        self::assertCount(2, $names);
        self::assertCount(2, array_unique($names));
    }

    public function testDiscoveryHidesOnlyHidden(): void
    {
        $qb = $this->builder();
        MagazineAccessClause::applyDiscovery($qb, 'm', $this->viewer());
        self::assertStringContainsString("m.access <> 'hidden'", $qb->getDQL());

        $qb = $this->builder();
        MagazineAccessClause::applyDiscovery($qb, 'm', null);
        self::assertStringContainsString("m.access <> 'hidden'", $qb->getDQL());

        $qb = $this->builder();
        $before = $qb->getDQL();
        MagazineAccessClause::applyDiscovery($qb, 'm', $this->viewer(admin: true));
        self::assertSame($before, $qb->getDQL());
    }

    public function testRejectsUnsafeAlias(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MagazineAccessClause::sql('m; DROP TABLE x', null);
    }

    private function builder(): QueryBuilder
    {
        return (new QueryBuilder($this->createStub(EntityManagerInterface::class)))
            ->select('m')
            ->from(Magazine::class, 'm');
    }

    private function viewer(bool $admin = false, bool $globalModerator = false): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(42);
        $user->method('isAdmin')->willReturn($admin);
        $user->method('isModerator')->willReturn($globalModerator);

        return $user;
    }
}
