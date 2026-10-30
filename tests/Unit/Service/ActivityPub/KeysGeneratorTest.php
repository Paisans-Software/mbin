<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\ActivityPub;

use App\Entity\User;
use App\Service\ActivityPub\KeysGenerator;
use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\RSA\PrivateKey;
use phpseclib3\Crypt\RSA\PublicKey;
use PHPUnit\Framework\TestCase;

class KeysGeneratorTest extends TestCase
{
    protected function tearDown(): void
    {
        KeysGenerator::resetKeyPool();
        parent::tearDown();
    }

    public function testGeneratesARealKeyPairWithoutAPool(): void
    {
        $actor = $this->actorWithKey();

        $this->assertKeyPairMatches($actor);
        $privateKey = RSA::loadPrivateKey((string) $actor->privateKey);
        self::assertInstanceOf(PrivateKey::class, $privateKey);
        self::assertSame(4096, $privateKey->getLength());
        self::assertNotContains($actor->privateKey, array_map($this->normalised(...), $this->poolKeys()));
    }

    public function testHandsOutPoolKeysAndGivesConsecutiveActorsDifferentKeys(): void
    {
        $pool = $this->poolKeys();
        KeysGenerator::useKeyPool($pool);

        $first = $this->actorWithKey();
        $second = $this->actorWithKey();

        $this->assertKeyPairMatches($first);
        $this->assertKeyPairMatches($second);
        self::assertSame($this->normalised($pool[0]), $first->privateKey);
        self::assertSame($this->normalised($pool[1]), $second->privateKey);
        self::assertNotSame($first->privateKey, $second->privateKey);
        self::assertNotSame($first->publicKey, $second->publicKey);
    }

    public function testRotationWithAPoolChangesTheKeyAndRevertRestoresIt(): void
    {
        KeysGenerator::useKeyPool($this->poolKeys());
        $actor = $this->actorWithKey();
        $originalPrivate = $actor->privateKey;
        $originalPublic = $actor->publicKey;

        $actor->rotatePrivateKey();

        $this->assertKeyPairMatches($actor);
        self::assertNotSame($originalPrivate, $actor->privateKey);
        self::assertNotSame($originalPublic, $actor->publicKey);
        self::assertSame($originalPrivate, $actor->oldPrivateKey);

        $actor->rotatePrivateKey(true);

        self::assertSame($originalPrivate, $actor->privateKey);
        self::assertSame($originalPublic, $actor->publicKey);
    }

    public function testRotationNeverHandsBackTheCurrentKeyWhenThePoolWrapsAround(): void
    {
        $pool = \array_slice($this->poolKeys(), 0, 2);
        KeysGenerator::useKeyPool($pool);
        $actor = $this->actorWithKey();
        // The next key in turn is the second one; take it so the pool wraps back to the first.
        $this->actorWithKey();

        $before = $actor->privateKey;
        $actor->rotatePrivateKey();

        self::assertNotSame($before, $actor->privateKey);
        $this->assertKeyPairMatches($actor);
    }

    public function testResetRestoresRealKeyGeneration(): void
    {
        KeysGenerator::useKeyPool($this->poolKeys());
        KeysGenerator::resetKeyPool();

        $actor = $this->actorWithKey();

        self::assertNotContains($actor->privateKey, array_map($this->normalised(...), $this->poolKeys()));
    }

    public function testThePoolIsRefusedOutsideTheTestEnvironment(): void
    {
        foreach (['prod', 'dev', null] as $env) {
            $this->withAppEnv($env, function () use ($env): void {
                try {
                    KeysGenerator::useKeyPool($this->poolKeys());
                    self::fail('useKeyPool() accepted APP_ENV '.var_export($env, true));
                } catch (\LogicException) {
                }
            });
        }

        // Nothing was set, so generation is still real.
        $actor = $this->actorWithKey();
        $privateKey = RSA::loadPrivateKey((string) $actor->privateKey);
        self::assertInstanceOf(PrivateKey::class, $privateKey);
        self::assertSame(4096, $privateKey->getLength());
    }

    public function testAnEmptyPoolIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        KeysGenerator::useKeyPool([]);
    }

    private function assertKeyPairMatches(User $actor): void
    {
        self::assertNotNull($actor->privateKey);
        self::assertNotNull($actor->publicKey);
        $privateKey = RSA::loadPrivateKey($actor->privateKey);
        $publicKey = RSA::loadPublicKey($actor->publicKey);
        self::assertInstanceOf(PrivateKey::class, $privateKey);
        self::assertInstanceOf(PublicKey::class, $publicKey);
        self::assertSame((string) $privateKey->getPublicKey(), $actor->publicKey);
        self::assertTrue($publicKey->verify('message', $privateKey->sign('message')));
    }

    private function withAppEnv(?string $env, callable $callback): void
    {
        $hadServer = \array_key_exists('APP_ENV', $_SERVER);
        $hadEnv = \array_key_exists('APP_ENV', $_ENV);
        $server = $_SERVER['APP_ENV'] ?? null;
        $envValue = $_ENV['APP_ENV'] ?? null;
        try {
            if (null === $env) {
                unset($_SERVER['APP_ENV'], $_ENV['APP_ENV']);
            } else {
                $_SERVER['APP_ENV'] = $env;
                $_ENV['APP_ENV'] = $env;
            }
            $callback();
        } finally {
            unset($_SERVER['APP_ENV'], $_ENV['APP_ENV']);
            if ($hadServer) {
                $_SERVER['APP_ENV'] = $server;
            }
            if ($hadEnv) {
                $_ENV['APP_ENV'] = $envValue;
            }
        }
    }

    /**
     * The PEM exactly as KeysGenerator stores it.
     */
    private function normalised(string $pem): string
    {
        $privateKey = RSA::loadPrivateKey($pem);
        self::assertInstanceOf(PrivateKey::class, $privateKey);

        return (string) $privateKey;
    }

    /**
     * @return string[]
     */
    private function poolKeys(): array
    {
        $paths = glob(\dirname(__DIR__, 3).'/assets/test_only_actor_keys/actor_key_*.pem');
        sort($paths);
        self::assertCount(8, $paths);

        return array_map(file_get_contents(...), $paths);
    }

    private function actorWithKey(): User
    {
        $actor = new User('actor@example.com', 'actor', 'password', User::USER_TYPE_PERSON);
        KeysGenerator::generate($actor);

        return $actor;
    }
}
