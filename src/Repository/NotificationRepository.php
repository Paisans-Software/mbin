<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Entry;
use App\Entity\EntryComment;
use App\Entity\Magazine;
use App\Entity\MagazineAccessRequestedNotification;
use App\Entity\Moderator;
use App\Entity\Notification;
use App\Entity\Post;
use App\Entity\PostComment;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Exception\NotValidCurrentPageException;
use Pagerfanta\Pagerfanta;
use Pagerfanta\PagerfantaInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @method Notification|null find($id, $lockMode = null, $lockVersion = null)
 * @method Notification|null findOneBy(array $criteria, array $orderBy = null)
 * @method Notification[]    findAll()
 * @method Notification[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class NotificationRepository extends ServiceEntityRepository
{
    public const PER_PAGE = 25;
    public const STATUS_ALL = 'all';
    public const STATUS_OPTIONS = [
        self::STATUS_ALL,
        Notification::STATUS_NEW,
        Notification::STATUS_READ,
    ];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notification::class);
    }

    public function findByUser(
        User $user,
        ?int $page,
        string $status = self::STATUS_ALL,
        int $perPage = self::PER_PAGE,
    ): PagerfantaInterface {
        $qb = $this->createQueryBuilder('n')
            ->where('n.user = :user')
            ->setParameter('user', $user)
            ->orderBy('n.id', 'DESC');

        if (self::STATUS_ALL !== $status) {
            $qb->andWhere('n.status = :status')
                ->setParameter('status', $status);
        }

        // FORK DIVERGENCE — do not offer this upstream: private magazines
        $this->excludeUnviewableContent($qb, $user);
        $this->excludeStaleAccessRequests($qb, $user);

        $pagerfanta = new Pagerfanta(
            new QueryAdapter(
                $qb
            )
        );

        try {
            $pagerfanta->setMaxPerPage($perPage);
            $pagerfanta->setCurrentPage($page);
        } catch (NotValidCurrentPageException $e) {
            throw new NotFoundHttpException();
        }

        return $pagerfanta;
    }

    /**
     * Drop the user's notifications about content (or a report on content) in a magazine
     * the user cannot see now, for example after being removed from a private magazine.
     * The content columns belong to the notification subclasses, which DQL on the root
     * class cannot reach, so each subclass that carries one gets its own subquery, correlated on
     * the notification id. The exclusion stays inside the paginated query: no id list is
     * fetched or bound.
     *
     * FORK DIVERGENCE — do not offer this upstream: private magazines
     */
    private function excludeUnviewableContent(QueryBuilder $qb, User $user): void
    {
        $em = $this->getEntityManager();
        $subclasses = $em->getClassMetadata(Notification::class)->subClasses;
        sort($subclasses);

        $i = 0;
        foreach ($subclasses as $subclass) {
            $metadata = $em->getClassMetadata($subclass);
            foreach (['entry', 'entryComment', 'post', 'postComment', 'report'] as $field) {
                if (!$metadata->hasAssociation($field)) {
                    continue;
                }

                $magazine = 'nam_m'.$i;
                $condition = MagazineAccessClause::dql($qb, $magazine, $user);
                if (null === $condition) {
                    return;
                }

                $content = 'nam_c'.$i;
                $notification = 'nam_n'.$i;
                $qb->andWhere(\sprintf(
                    'NOT EXISTS (SELECT %1$s.id FROM %2$s %1$s JOIN %1$s.%3$s %4$s JOIN %4$s.magazine %5$s WHERE %1$s.id = n.id AND NOT %6$s)',
                    $notification,
                    $subclass,
                    $field,
                    $content,
                    $magazine,
                    $condition,
                ));
                ++$i;
            }
        }
    }

    /**
     * Drop the user's access-request notifications for a magazine they no longer moderate:
     * the requester's name is for moderators only. Correlated on the notification id, like
     * excludeUnviewableContent(); admins and global moderators keep everything.
     *
     * FORK DIVERGENCE — do not offer this upstream: private magazines
     */
    private function excludeStaleAccessRequests(QueryBuilder $qb, User $user): void
    {
        if ($user->isAdmin() || $user->isModerator()) {
            return;
        }

        $qb->andWhere(\sprintf(
            'NOT EXISTS (SELECT nar.id FROM %s nar WHERE nar.id = n.id AND NOT EXISTS (SELECT nmd.id FROM %s nmd WHERE nmd.magazine = nar.magazine AND nmd.user = :nar_viewer AND (nmd.isConfirmed = true OR nmd.isOwner = true)))',
            MagazineAccessRequestedNotification::class,
            Moderator::class,
        ));
        $qb->setParameter('nar_viewer', $user->getId());
    }

    public function findUnreadEntryNotifications(User $user, Entry $entry): iterable
    {
        $result = $this->findUnreadNotifications($user);

        return array_filter(
            $result,
            fn ($notification) => (isset($notification->entry) && $notification->entry === $entry)
                || (isset($notification->entryComment) && $notification->entryComment->entry === $entry)
        );
    }

    public function findUnreadNotifications(User $user): array
    {
        $dql = 'SELECT n FROM '.Notification::class.' n WHERE n.user = :user AND n.status = :status';

        return $this->getEntityManager()->createQuery($dql)
            ->setParameter('user', $user)
            ->setParameter('status', Notification::STATUS_NEW)
            ->getResult();
    }

    public function countUnreadNotifications(User $user): int
    {
        $dql = 'SELECT count(n.id) FROM '.Notification::class.' n WHERE n.user = :user AND n.status = :status';

        return $this->getEntityManager()->createQuery($dql)
            ->setParameter('user', $user)
            ->setParameter('status', Notification::STATUS_NEW)
            ->getSingleScalarResult();
    }

    public function findUnreadPostNotifications(User $user, Post $post): iterable
    {
        $result = $this->findUnreadNotifications($user);

        return array_filter(
            $result,
            fn ($notification) => (isset($notification->post) && $notification->post === $post)
                || (isset($notification->postComment) && $notification->postComment->post === $post)
        );
    }

    public function removeEntryNotifications(Entry $entry): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = 'DELETE FROM notification AS n WHERE n.entry_id = :entryId';

        $stmt = $conn->prepare($sql);
        $stmt->bindValue('entryId', $entry->getId());

        $stmt->executeQuery();
    }

    public function removeEntryCommentNotifications(EntryComment $comment): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = 'DELETE FROM notification AS n WHERE n.entry_comment_id = :commentId';

        $stmt = $conn->prepare($sql);
        $stmt->bindValue('commentId', $comment->getId());

        $stmt->executeQuery();
    }

    public function removePostNotifications(Post $post): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = 'DELETE FROM notification AS n WHERE n.post_id = :postId';

        $stmt = $conn->prepare($sql);
        $stmt->bindValue('postId', $post->getId());

        $stmt->executeQuery();
    }

    public function removePostCommentNotifications(PostComment $comment): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = 'DELETE FROM notification AS n WHERE n.post_comment_id = :commentId';

        $stmt = $conn->prepare($sql);
        $stmt->bindValue('commentId', $comment->getId());

        $stmt->executeQuery();
    }

    public function markReportNotificationsAsRead(User $user): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = 'UPDATE notification n SET status = :s
                      WHERE n.user_id = :uId
                        AND n.report_id IS NOT NULL';
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('s', Notification::STATUS_READ);
        $stmt->bindValue('uId', $user->getId());
        $stmt->executeQuery();
    }

    public function markReportNotificationsInMagazineAsRead(User $user, Magazine $magazine): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = 'UPDATE notification n SET status = :s
                      WHERE n.user_id = :uId
                        AND n.report_id IS NOT NULL
                        AND EXISTS (SELECT id FROM report r WHERE r.id = n.report_id AND r.magazine_id = :mId)';
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('s', Notification::STATUS_READ);
        $stmt->bindValue('uId', $user->getId());
        $stmt->bindValue('mId', $magazine->getId());
        $stmt->executeQuery();
    }

    public function markOwnReportNotificationsAsRead(User $user): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = 'UPDATE notification n SET status = :s
                      WHERE n.user_id = :uId
                        AND n.report_id IS NOT NULL
                        AND EXISTS (SELECT id FROM report r WHERE r.id = n.report_id AND r.reporting_id = :uId)';
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('s', Notification::STATUS_READ);
        $stmt->bindValue('uId', $user->getId());
        $stmt->executeQuery();
    }

    public function markUserSignupNotificationsAsRead(User $user, User $signedUpUser): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = 'UPDATE notification n SET status = :s
                      WHERE n.user_id = :uId
                        AND n.new_user_id = :newUserId';
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('s', Notification::STATUS_READ);
        $stmt->bindValue('uId', $user->getId());
        $stmt->bindValue('newUserId', $signedUpUser->getId());
        $stmt->executeQuery();
    }
}
