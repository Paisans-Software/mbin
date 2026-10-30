<?php

declare(strict_types=1);

namespace App\Service\ActivityPub;

use App\Entity\Contracts\ActivityPubActorInterface;
use phpseclib3\Crypt\RSA;

class KeysGenerator
{
    /**
     * Private keys handed out instead of generating new ones. Only ever set by the test suite.
     *
     * @var string[]|null
     */
    private static ?array $keyPool = null;
    private static int $keyPoolIndex = 0;

    public static function generate(ActivityPubActorInterface $actor): ActivityPubActorInterface
    {
        $privateKey = null !== self::$keyPool
            ? self::takeFromPool($actor->getPrivateKey())
            : RSA::createKey(4096);

        $actor->publicKey = (string) $privateKey->getPublicKey();
        $actor->privateKey = (string) $privateKey;

        return $actor;
    }

    /**
     * Makes generate() hand out these private keys in turn instead of generating new ones,
     * because generating a 4096-bit key takes about a quarter of a second.
     * Only allowed when APP_ENV is "test".
     *
     * @param string[] $privateKeyPems
     */
    public static function useKeyPool(array $privateKeyPems): void
    {
        if ('test' !== ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null)) {
            throw new \LogicException('A key pool may only be used when APP_ENV is "test".');
        }
        if ([] === $privateKeyPems) {
            throw new \InvalidArgumentException('A key pool needs at least one key.');
        }

        self::$keyPool = array_values($privateKeyPems);
        self::$keyPoolIndex = 0;
    }

    public static function resetKeyPool(): void
    {
        self::$keyPool = null;
        self::$keyPoolIndex = 0;
    }

    private static function takeFromPool(?string $currentPrivateKey): RSA\PrivateKey
    {
        $privateKey = self::loadPoolKey();
        // A rotation must never hand back the key the actor already has.
        if (\count(self::$keyPool) > 1 && (string) $privateKey === $currentPrivateKey) {
            $privateKey = self::loadPoolKey();
        }

        return $privateKey;
    }

    private static function loadPoolKey(): RSA\PrivateKey
    {
        $pem = self::$keyPool[self::$keyPoolIndex++ % \count(self::$keyPool)];
        $privateKey = RSA::loadPrivateKey($pem);
        if (!$privateKey instanceof RSA\PrivateKey) {
            throw new \InvalidArgumentException('The key pool holds something that is not an RSA private key.');
        }

        return $privateKey;
    }
}
