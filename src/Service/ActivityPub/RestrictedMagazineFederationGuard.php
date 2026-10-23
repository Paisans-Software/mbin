<?php

declare(strict_types=1);

namespace App\Service\ActivityPub;

use App\Repository\MagazineRepository;
use App\Service\SettingsManager;

/**
 * Decides whether an outbound ActivityPub payload is about a restricted magazine.
 *
 * Nothing about a private or hidden magazine may leave the instance: not its content,
 * not a vote on it, not a boost of it, not a mention made inside it. Rather than teach
 * every wrapper and handler about access levels, the payload itself is inspected: an
 * activity is about a restricted magazine when any string in it is one of our own URLs
 * under /m/<name> for a restricted <name>. Every local object URL Mbin builds for
 * magazine content (entries, comments, posts, replies) and for the magazine actor and
 * its collections has that shape, so the walk catches the audience field, the object
 * id, inReplyTo, to/cc and anything nested.
 *
 * The one exemption is the magazine's own follow handshake, so that a restricted
 * magazine can still follow remote actors.
 *
 * FORK DIVERGENCE — do not offer this upstream: private magazines
 */
readonly class RestrictedMagazineFederationGuard
{
    private const string MAGAZINE_PATH = '#^/m/([a-zA-Z0-9_]{2,25})(?:/|$)#';

    public function __construct(
        private SettingsManager $settingsManager,
        private MagazineRepository $magazineRepository,
    ) {
    }

    /**
     * @param array<mixed> $payload the built ActivityPub JSON
     */
    public function touchesRestrictedMagazine(array $payload): bool
    {
        $names = [];
        $this->collectMagazineNames($payload, $names);

        foreach (array_keys($names) as $name) {
            if ($this->isRestrictedName((string) $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A Follow or an Undo of a Follow whose actor is a restricted local magazine: the
     * magazine following (or unfollowing) somebody itself. A restricted magazine never
     * sends a Reject, since a remote Follow of it is dropped silently.
     *
     * @param array<mixed> $payload
     */
    public function isAllowedHandshake(array $payload): bool
    {
        $type = $payload['type'] ?? null;
        $isHandshake = match ($type) {
            'Follow' => true,
            'Undo' => \is_array($payload['object'] ?? null) && 'Follow' === ($payload['object']['type'] ?? null),
            default => false,
        };

        if (!$isHandshake || !\is_string($payload['actor'] ?? null) || !$this->settingsManager->isLocalUrl($payload['actor'])) {
            return false;
        }

        $path = parse_url($payload['actor'], PHP_URL_PATH);
        if (!\is_string($path) || 1 !== preg_match('#^/m/([a-zA-Z0-9_]{2,25})/?$#', $path, $matches)) {
            return false;
        }

        return $this->isRestrictedName($matches[1]);
    }

    /**
     * @param array<mixed>        $value
     * @param array<string, true> $names collected once each, so each name is looked up once per call
     */
    private function collectMagazineNames(array $value, array &$names): void
    {
        foreach ($value as $item) {
            if (\is_array($item)) {
                $this->collectMagazineNames($item, $names);
                continue;
            }

            // a whole string that is a URL, not a body that merely starts with one
            if (!\is_string($item) || !str_starts_with($item, 'http') || false === filter_var($item, FILTER_VALIDATE_URL)
                || !$this->settingsManager->isLocalUrl($item)) {
                continue;
            }

            $path = parse_url($item, PHP_URL_PATH);
            if (\is_string($path) && 1 === preg_match(self::MAGAZINE_PATH, $path, $matches)) {
                $names[strtolower($matches[1])] = true;
            }
        }
    }

    private function isRestrictedName(string $name): bool
    {
        return true === $this->magazineRepository->findOneByName($name)?->isRestricted();
    }
}
