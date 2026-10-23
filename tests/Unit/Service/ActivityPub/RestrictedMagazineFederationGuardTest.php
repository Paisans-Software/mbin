<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ActivityPub;

use App\Entity\Magazine;
use App\Enum\MagazineAccess;
use App\Repository\MagazineRepository;
use App\Service\ActivityPub\RestrictedMagazineFederationGuard;
use App\Service\SettingsManager;
use PHPUnit\Framework\TestCase;

/**
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class RestrictedMagazineFederationGuardTest extends TestCase
{
    private const string HOST = 'local.example';

    /** @var array<string, int> */
    private array $lookups = [];

    private function guard(): RestrictedMagazineFederationGuard
    {
        $settings = $this->createStub(SettingsManager::class);
        $settings->method('isLocalUrl')->willReturnCallback(
            fn (string $url) => self::HOST === parse_url($url, PHP_URL_HOST)
        );

        $circle = new Magazine('circle', 'Circle', null, null, null, false, false, null);
        $circle->access = MagazineAccess::Private;
        $open = new Magazine('open', 'Open', null, null, null, false, false, null);

        $magazines = $this->createStub(MagazineRepository::class);
        $magazines->method('findOneByName')->willReturnCallback(function (?string $name) use ($circle, $open) {
            $this->lookups[$name] = ($this->lookups[$name] ?? 0) + 1;

            return match ($name) {
                'circle' => $circle,
                'open' => $open,
                default => null,
            };
        });

        return new RestrictedMagazineFederationGuard($settings, $magazines);
    }

    public function testDetectsMagazineInAudience(): void
    {
        self::assertTrue($this->guard()->touchesRestrictedMagazine([
            'type' => 'Create',
            'actor' => 'https://local.example/u/alice',
            'audience' => 'https://local.example/m/circle',
            'object' => ['type' => 'Page', 'id' => 'https://local.example/m/circle/t/5/-'],
        ]));
    }

    public function testDetectsEntryUrlInObject(): void
    {
        self::assertTrue($this->guard()->touchesRestrictedMagazine([
            'type' => 'Like',
            'actor' => 'https://local.example/u/alice',
            'object' => 'https://local.example/m/circle/t/5/-',
        ]));
    }

    public function testDetectsCommentUrlNestedInObjectInReplyTo(): void
    {
        self::assertTrue($this->guard()->touchesRestrictedMagazine([
            'type' => 'Create',
            'actor' => 'https://local.example/u/alice',
            'to' => ['https://www.w3.org/ns/activitystreams#Public'],
            'object' => [
                'type' => 'Note',
                'id' => 'https://local.example/u/alice/note/1',
                'inReplyTo' => 'https://local.example/m/circle/t/5/-/comment/9',
            ],
        ]));
    }

    public function testIgnoresPublicMagazine(): void
    {
        self::assertFalse($this->guard()->touchesRestrictedMagazine([
            'type' => 'Create',
            'actor' => 'https://local.example/u/alice',
            'audience' => 'https://local.example/m/open',
            'to' => ['https://local.example/m/open', 'https://local.example/m/open/followers'],
            'object' => ['type' => 'Page', 'id' => 'https://local.example/m/open/t/6/-'],
        ]));
    }

    public function testIgnoresRemoteUrlWithSamePath(): void
    {
        self::assertFalse($this->guard()->touchesRestrictedMagazine([
            'type' => 'Like',
            'actor' => 'https://local.example/u/alice',
            'object' => 'https://other.example/m/circle/t/5/-',
        ]));
    }

    public function testIgnoresABodyThatMerelyStartsWithALink(): void
    {
        self::assertFalse($this->guard()->touchesRestrictedMagazine([
            'type' => 'Create',
            'object' => [
                'id' => 'https://local.example/m/open/p/7/-',
                'source' => ['content' => "https://local.example/m/circle/t/5/-\nis where we talked about it"],
            ],
        ]));
    }

    public function testLooksEachNameUpOncePerCall(): void
    {
        $this->guard()->touchesRestrictedMagazine([
            'type' => 'Create',
            'audience' => 'https://local.example/m/open',
            'to' => ['https://local.example/m/open', 'https://local.example/m/open/followers'],
            'object' => ['id' => 'https://local.example/m/open/t/6/-', 'audience' => 'https://local.example/m/open'],
        ]);

        self::assertSame(['open' => 1], $this->lookups);
    }

    public function testHandshakeAllowedOnlyForMagazineActor(): void
    {
        $guard = $this->guard();
        $follow = [
            'type' => 'Follow',
            'actor' => 'https://local.example/m/circle',
            'object' => 'https://remote.example/u/bob',
        ];

        self::assertTrue($guard->isAllowedHandshake($follow));
        self::assertTrue($guard->isAllowedHandshake([
            'type' => 'Undo',
            'actor' => 'https://local.example/m/circle',
            'object' => $follow,
        ]));
        self::assertFalse($guard->isAllowedHandshake([
            'type' => 'Reject',
            'actor' => 'https://local.example/m/circle',
            'object' => ['type' => 'Follow', 'actor' => 'https://remote.example/u/bob', 'object' => 'https://local.example/m/circle'],
        ]));

        self::assertFalse($guard->isAllowedHandshake([
            'type' => 'Follow',
            'actor' => 'https://local.example/u/alice',
            'object' => 'https://local.example/m/circle',
        ]));
        self::assertFalse($guard->isAllowedHandshake([
            'type' => 'Create',
            'actor' => 'https://local.example/m/circle',
            'object' => ['type' => 'Note', 'id' => 'https://local.example/m/circle/p/1/-'],
        ]));
        self::assertFalse($guard->isAllowedHandshake([
            'type' => 'Undo',
            'actor' => 'https://local.example/m/circle',
            'object' => ['type' => 'Like', 'object' => 'https://local.example/m/circle/t/5/-'],
        ]));
        self::assertFalse($guard->isAllowedHandshake([
            'type' => 'Follow',
            'actor' => 'https://local.example/m/open',
            'object' => 'https://remote.example/u/bob',
        ]), 'only a restricted magazine needs the exemption');
        self::assertFalse($guard->isAllowedHandshake([
            'type' => 'Follow',
            'actor' => 'https://other.example/m/circle',
            'object' => 'https://remote.example/u/bob',
        ]));
    }
}
