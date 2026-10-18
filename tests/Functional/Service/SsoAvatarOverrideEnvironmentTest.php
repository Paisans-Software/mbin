<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use App\Service\SettingsManager;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * MBIN_SSO_AVATAR_OVERRIDE defaults to true, so an instance that never sets
 * it, or sets it empty, must keep letting members upload their own avatar.
 */
class SsoAvatarOverrideEnvironmentTest extends WebTestCase
{
    private const string NAME = 'MBIN_SSO_AVATAR_OVERRIDE';

    /** @var array{0: ?string, 1: ?string} */
    private array $previousEnvironment = [null, null];

    public function setUp(): void
    {
        $this->previousEnvironment = [$_SERVER[self::NAME] ?? null, $_ENV[self::NAME] ?? null];
        unset($_SERVER[self::NAME], $_ENV[self::NAME]);
        putenv(self::NAME);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        [$server, $env] = $this->previousEnvironment;

        if (null === $server) {
            unset($_SERVER[self::NAME]);
        } else {
            $_SERVER[self::NAME] = $server;
        }

        if (null === $env) {
            unset($_ENV[self::NAME]);
        } else {
            $_ENV[self::NAME] = $env;
        }
    }

    /**
     * @return iterable<string, array{0: ?string, 1: bool}>
     */
    public static function environmentValues(): iterable
    {
        yield 'unset' => [null, true];
        yield 'empty' => ['', true];
        yield 'true' => ['true', true];
        yield 'false' => ['false', false];
    }

    #[DataProvider('environmentValues')]
    public function testTheEnvironmentValueIsReadWithATrueDefault(?string $value, bool $expected): void
    {
        if (null !== $value) {
            $_SERVER[self::NAME] = $_ENV[self::NAME] = $value;
        }

        parent::setUp();

        $this->assertSame($expected, self::getContainer()->get(SettingsManager::class)->isSsoAvatarOverrideAllowed());
    }
}
