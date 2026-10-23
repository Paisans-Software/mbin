<?php

declare(strict_types=1);

namespace App\Service\ActivityPub;

use App\Entity\Entry;
use App\Entity\Magazine;
use App\Entity\Post;
use App\Repository\EntryRepository;
use App\Repository\MagazineFollowRepository;
use App\Utils\Slugger;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use League\CommonMark\CommonMarkConverter;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gives a restricted magazine a private link thread (a shadow) for each post from an
 * actor it follows. The original is filed publicly as if the magazine did not follow,
 * and the shadow links to it, so the restricted magazine discusses it among its members
 * without anything leaving the instance.
 *
 * A shadow is a local thread built directly, without EntryManager, so that nothing is
 * dispatched for it: no notifications, no realtime updates and no federation.
 *
 * FORK DIVERGENCE — do not offer this upstream: private magazines
 */
class ShadowThreadManager
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ManagerRegistry $managerRegistry,
        private readonly MagazineFollowRepository $magazineFollowRepository,
        private readonly EntryRepository $entryRepository,
        private readonly TranslatorInterface $translator,
        private readonly Slugger $slugger,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $object the ActivityPub object the original was created from
     */
    public function shadow(Entry|Post $original, array $object, ?string $deliveredBy, ?string $activityType): void
    {
        if (null === $activityType || $original->magazine->isRestricted()) {
            return;
        }

        $magazines = $this->magazineFollowRepository->findRestrictedMagazinesFollowing(
            [$deliveredBy, $original->user->apProfileId],
            $activityType
        );

        foreach ($magazines as $magazine) {
            if ($this->hasShadow($original, $magazine)) {
                continue;
            }

            $shadow = new Entry(
                $original instanceof Entry ? $this->titleForEntry($original) : $this->titleForPost($original),
                $this->urlOf($object),
                $original->body,
                $magazine,
                $original->user,
                $original->isAdult,
                false,
                $original->lang,
            );
            $shadow->type = Entry::ENTRY_TYPE_LINK;
            $shadow->slug = $this->slugger->slug($shadow->title);
            if ($original instanceof Entry) {
                $shadow->shadowOfEntry = $original;
            } else {
                $shadow->shadowOfPost = $original;
            }
            $shadow->updateScore();
            $shadow->updateRanking();

            try {
                $this->entityManager->persist($shadow);
                $this->entityManager->flush();
            } catch (UniqueConstraintViolationException) {
                // another worker shadowed the same original into this magazine between the
                // check above and this insert; its shadow stands, and the inbox message must
                // not fail over a duplicate. A failed flush closes the entity manager.
                $this->logger->info('[ShadowThreadManager::shadow] {o} is already shadowed in {m}', ['o' => $object['id'] ?? '<no id>', 'm' => $magazine->name]);
                $this->managerRegistry->resetManager();

                return;
            }

            $magazine->entryCount = $this->entryRepository->countEntriesByMagazine($magazine);
            $this->entityManager->flush();
        }
    }

    /**
     * A thread title for a microblog post, which has none: the first line of its text,
     * cut on a word boundary to fit.
     */
    public function titleForPost(Post $post): string
    {
        $html = (new CommonMarkConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]))
            ->convert($post->body ?? '')
            ->getContent();
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $firstLine = '';
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? '');
            if ('' !== $line) {
                $firstLine = $line;
                break;
            }
        }

        if ('' === $firstLine) {
            return $this->translator->trans('shadow_untitled');
        }

        return self::truncate($firstLine, Entry::MAX_TITLE_LENGTH);
    }

    private function titleForEntry(Entry $entry): string
    {
        $title = trim($entry->title);

        return '' === $title ? $this->translator->trans('shadow_untitled') : self::truncate($title, Entry::MAX_TITLE_LENGTH);
    }

    private static function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $ellipsis = '…';
        $cut = mb_substr($text, 0, $max - mb_strlen($ellipsis));
        // the cut already ends on a word boundary when the next character is a space
        if (' ' !== mb_substr($text, mb_strlen($cut), 1)) {
            $space = mb_strrpos($cut, ' ');
            if (false !== $space && $space > 0) {
                $cut = mb_substr($cut, 0, $space);
            }
        }

        return rtrim($cut).$ellipsis;
    }

    /**
     * @param array<string, mixed> $object
     */
    private function urlOf(array $object): ?string
    {
        $url = $object['url'] ?? null;
        if (\is_string($url) && '' !== $url) {
            return $url;
        }
        if (\is_array($url)) {
            // a single Link object, or a list of them
            $links = isset($url['href']) ? [$url] : $url;
            foreach ($links as $link) {
                if (\is_array($link) && isset($link['href']) && \is_string($link['href'])) {
                    return $link['href'];
                }
            }
        }

        return \is_string($object['id'] ?? null) ? $object['id'] : null;
    }

    private function hasShadow(Entry|Post $original, Magazine $magazine): bool
    {
        return null !== $this->entryRepository->findOneBy(
            $original instanceof Entry
                ? ['magazine' => $magazine, 'shadowOfEntry' => $original]
                : ['magazine' => $magazine, 'shadowOfPost' => $original]
        );
    }
}
