<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MagazineBan;
use App\Entity\MagazineMember;
use App\Entity\Moderator;
use App\Entity\User;
use App\Enum\MagazineAccess;
use Doctrine\ORM\QueryBuilder;

/**
 * The listing form of Magazine::userCanView(): a WHERE condition that keeps only rows
 * whose magazine the viewer may see. A public magazine is visible to everyone; a private
 * or hidden one to its members who are not banned and to its moderators; every magazine
 * to admins and global moderators.
 *
 * Nothing here is cached: membership is read by the query itself, so removing a member
 * takes effect on their next request.
 *
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
final class MagazineAccessClause
{
    /**
     * Restrict a DQL query to magazines the viewer may see. $magazineAlias is the alias
     * of a Magazine already joined or selected in $qb.
     */
    public static function apply(QueryBuilder $qb, string $magazineAlias, ?User $viewer): void
    {
        $condition = self::dql($qb, $magazineAlias, $viewer);
        if (null !== $condition) {
            $qb->andWhere($condition);
        }
    }

    /**
     * The condition apply() adds, for callers that must combine it with something else,
     * such as an outer join that may produce no magazine at all. Binds its parameter on
     * $qb. Returns null when the viewer sees every magazine.
     */
    public static function dql(QueryBuilder $qb, string $magazineAlias, ?User $viewer): ?string
    {
        $a = self::checkAlias($magazineAlias);
        if (self::seesEverything($viewer)) {
            return null;
        }

        $public = "$a.access = '".MagazineAccess::Public->value."'";
        if (null === $viewer) {
            return $public;
        }

        $param = "mac_viewer_$a";
        $qb->setParameter($param, $viewer->getId());

        $member = "SELECT mac_mm_$a.id FROM ".MagazineMember::class." mac_mm_$a"
            ." WHERE mac_mm_$a.magazine = $a AND mac_mm_$a.user = :$param";
        $ban = "SELECT mac_mb_$a.id FROM ".MagazineBan::class." mac_mb_$a"
            ." WHERE mac_mb_$a.magazine = $a AND mac_mb_$a.user = :$param"
            ." AND (mac_mb_$a.expiredAt IS NULL OR mac_mb_$a.expiredAt > CURRENT_TIMESTAMP())";
        $moderator = "SELECT mac_md_$a.id FROM ".Moderator::class." mac_md_$a"
            ." WHERE mac_md_$a.magazine = $a AND mac_md_$a.user = :$param"
            ." AND (mac_md_$a.isConfirmed = true OR mac_md_$a.isOwner = true)";

        return "($public OR (EXISTS ($member) AND NOT EXISTS ($ban)) OR EXISTS ($moderator))";
    }

    /**
     * The same rule for native SQL, where $magazineAlias is an alias of the magazine table.
     *
     * @return array{string, array<string, int>} the condition and the parameters it uses
     */
    public static function sql(string $magazineAlias, ?User $viewer): array
    {
        $a = self::checkAlias($magazineAlias);
        if (self::seesEverything($viewer)) {
            return ['TRUE', []];
        }

        $public = "$a.access = '".MagazineAccess::Public->value."'";
        if (null === $viewer) {
            return [$public, []];
        }

        $param = "mac_viewer_$a";
        $member = "SELECT 1 FROM magazine_member mac_mm_$a WHERE mac_mm_$a.magazine_id = $a.id AND mac_mm_$a.user_id = :$param";
        $ban = "SELECT 1 FROM magazine_ban mac_mb_$a WHERE mac_mb_$a.magazine_id = $a.id AND mac_mb_$a.user_id = :$param"
            ." AND (mac_mb_$a.expired_at IS NULL OR mac_mb_$a.expired_at > CURRENT_TIMESTAMP)";
        $moderator = "SELECT 1 FROM moderator mac_md_$a WHERE mac_md_$a.magazine_id = $a.id AND mac_md_$a.user_id = :$param"
            ." AND (mac_md_$a.is_confirmed = true OR mac_md_$a.is_owner = true)";

        return [
            "($public OR (EXISTS ($member) AND NOT EXISTS ($ban)) OR EXISTS ($moderator))",
            [$param => $viewer->getId()],
        ];
    }

    /**
     * Restrict a magazine discovery query (directory, search, suggestions): private
     * magazines stay listed so people can find and ask to join them, hidden ones do not.
     */
    public static function applyDiscovery(QueryBuilder $qb, string $magazineAlias, ?User $viewer): void
    {
        $a = self::checkAlias($magazineAlias);
        if (self::seesEverything($viewer)) {
            return;
        }

        $qb->andWhere("$a.access <> '".MagazineAccess::Hidden->value."'");
    }

    private static function seesEverything(?User $viewer): bool
    {
        return null !== $viewer && ($viewer->isAdmin() || $viewer->isModerator());
    }

    private static function checkAlias(string $alias): string
    {
        if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
            throw new \InvalidArgumentException("Not a query alias: $alias");
        }

        return $alias;
    }
}
