<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Entity\Entry;
use App\Repository\EntryRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Twig\Environment;

#[AsTwigComponent('entries_cross', template: 'components/_cached.html.twig')]
final class EntriesCrossComponent
{
    public ?Entry $entry = null;

    public function __construct(
        private readonly EntryRepository $repository,
        private readonly CacheInterface $cache,
        private readonly Environment $twig,
        private readonly RequestStack $requestStack,
        private readonly Security $security,
    ) {
    }

    public function getHtml(): string
    {
        $entryId = $this->entry->getId();
        $userId = $this->security->getUser()?->getId();
        $locale = $this->requestStack->getCurrentRequest()?->getLocale();

        // FORK DIVERGENCE — do not offer this upstream: private magazines
        // HTML naming an entry in a restricted magazine is not kept: a member removed from that
        // magazine must stop seeing it on their next request, not when the cache expires.
        $key = "entries_cross_{$entryId}_{$userId}_{$locale}";
        $restricted = false;
        $html = $this->cache->get(
            $key,
            function (ItemInterface $item) use ($entryId, &$restricted) {
                $item->expiresAfter(60);
                $entries = $this->repository->findCross($this->entry);
                foreach ($entries as $entry) {
                    $restricted = $restricted || $entry->magazine->isRestricted();
                }

                $item->tag(['entry_'.$entryId]);
                foreach ($entries as $entry) {
                    $item->tag(['entry_'.$entry->getId()]);
                }

                return $this->twig->render(
                    'components/entries_cross.html.twig',
                    [
                        'entries' => $this->repository->findCross($this->entry),
                    ]
                );
            }
        );
        if ($restricted) {
            $this->cache->delete($key);
        }

        return $html;
    }
}
