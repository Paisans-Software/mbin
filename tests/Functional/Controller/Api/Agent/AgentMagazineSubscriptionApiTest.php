<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api\Agent;

use App\DTO\MagazineBanDto;
use App\Entity\Magazine;
use App\Entity\User;
use App\Tests\WebTestCase;

class AgentMagazineSubscriptionApiTest extends WebTestCase
{
    use AgentTokenTrait;

    private const SCOPE = 'agent:magazine:subscribe';

    public function testPutSubscribes(): void
    {
        [$member, $magazine] = $this->target();

        $this->request('PUT', $member, $magazine, $this->agentToken(self::SCOPE));

        self::assertResponseStatusCodeSame(204);
        self::assertTrue($this->reloadMagazine($magazine)->isSubscribed($this->reloadUser($member)));
    }

    public function testPutTwiceIsIdempotent(): void
    {
        [$member, $magazine] = $this->target();
        $token = $this->agentToken(self::SCOPE);

        $this->request('PUT', $member, $magazine, $token);
        self::assertResponseStatusCodeSame(204);
        $this->request('PUT', $member, $magazine, $token);
        self::assertResponseStatusCodeSame(204);

        self::assertCount(1, $this->magazineSubscriptionRepository->findBy(['user' => $member->getId(), 'magazine' => $magazine->getId()]));
    }

    public function testDeleteUnsubscribes(): void
    {
        [$member, $magazine] = $this->target();
        $this->magazineManager->subscribe($magazine, $member);

        $this->request('DELETE', $member, $magazine, $this->agentToken(self::SCOPE));

        self::assertResponseStatusCodeSame(204);
        self::assertFalse($this->reloadMagazine($magazine)->isSubscribed($this->reloadUser($member)));
    }

    public function testDeleteWhenNotSubscribedIs204(): void
    {
        [$member, $magazine] = $this->target();

        $this->request('DELETE', $member, $magazine, $this->agentToken(self::SCOPE));

        self::assertResponseStatusCodeSame(204);
    }

    public function testWithoutTokenIs401(): void
    {
        [$member, $magazine] = $this->target();

        $this->client->request('PUT', $this->path($member->getId(), $magazine->getId()));

        self::assertResponseStatusCodeSame(401);
    }

    public function testWithoutScopeIsForbidden(): void
    {
        [$member, $magazine] = $this->target();

        $this->request('PUT', $member, $magazine, $this->agentToken('read'));

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reloadMagazine($magazine)->isSubscribed($this->reloadUser($member)));
    }

    public function testScopeWithoutRoleIsForbidden(): void
    {
        [$member, $magazine] = $this->target();

        $this->request('PUT', $member, $magazine, $this->agentToken(self::SCOPE, grantRole: false));

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reloadMagazine($magazine)->isSubscribed($this->reloadUser($member)));
    }

    public function testUnknownUserIs404(): void
    {
        [, $magazine] = $this->target();

        $this->client->request('PUT', $this->path(999999, $magazine->getId()), server: $this->auth($this->agentToken(self::SCOPE)));

        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownMagazineIs404(): void
    {
        [$member] = $this->target();

        $this->client->request('PUT', $this->path($member->getId(), 999999), server: $this->auth($this->agentToken(self::SCOPE)));

        self::assertResponseStatusCodeSame(404);
    }

    public function testRemoteUserRefused(): void
    {
        [$member, $magazine] = $this->target();
        $member->apId = 'https://remote.test/u/member';
        $this->entityManager->flush();

        $this->assertRefused($member, $magazine);
    }

    public function testBannedUserRefused(): void
    {
        [$member, $magazine] = $this->target();
        $member->isBanned = true;
        $this->entityManager->flush();

        $this->assertRefused($member, $magazine);
    }

    public function testDeletedUserRefused(): void
    {
        [$member, $magazine] = $this->target();
        $member->markedForDeletionAt = new \DateTime('+30 days');
        $this->entityManager->flush();

        $this->assertRefused($member, $magazine);
    }

    public function testAdminTargetRefused(): void
    {
        [, $magazine] = $this->target();
        $admin = $this->getUserByUsername('AdminUser', isAdmin: true);

        $this->assertRefused($admin, $magazine);
    }

    public function testAgentTargetRefused(): void
    {
        [$member, $magazine] = $this->target();
        $member->setOrRemoveAgentRole();
        $this->entityManager->flush();

        $this->assertRefused($member, $magazine);
    }

    public function testMagazineBannedTargetRefused(): void
    {
        [$member, $magazine] = $this->target();
        $this->magazineManager->ban($magazine, $member, $magazine->getOwner(), MagazineBanDto::create('test', new \DateTimeImmutable('+1 day')));

        $this->assertRefused($member, $magazine);
    }

    /**
     * @return array{User, Magazine}
     */
    private function target(): array
    {
        $owner = $this->getUserByUsername('JohnDoe');
        $member = $this->getUserByUsername('JaneDoe');
        $magazine = $this->getMagazineByName('welcome', $owner);

        return [$member, $magazine];
    }

    private function request(string $method, User $member, Magazine $magazine, string $token): void
    {
        $this->client->request($method, $this->path($member->getId(), $magazine->getId()), server: $this->auth($token));
    }

    private function assertRefused(User $member, Magazine $magazine): void
    {
        $this->request('PUT', $member, $magazine, $this->agentToken(self::SCOPE));

        self::assertResponseStatusCodeSame(400);
        self::assertFalse($this->reloadMagazine($magazine)->isSubscribed($this->reloadUser($member)));
    }

    private function path(int $userId, int $magazineId): string
    {
        return '/api/agent/users/'.$userId.'/magazines/'.$magazineId.'/subscription';
    }

    /**
     * @return array<string, string>
     */
    private function auth(string $token): array
    {
        return ['HTTP_AUTHORIZATION' => $token];
    }

    private function reloadMagazine(Magazine $magazine): Magazine
    {
        $this->entityManager->clear();

        return $this->magazineRepository->find($magazine->getId());
    }

    private function reloadUser(User $user): User
    {
        return $this->userRepository->find($user->getId());
    }
}
