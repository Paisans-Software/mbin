<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\Api\User;

use App\Entity\User;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The API counterpart of UserOidcAvatarTest: an upload by a member linked to
 * the OIDC provider is kept over the provider's picture, removing it hands
 * the avatar back, and with MBIN_SSO_AVATAR_OVERRIDE off both are refused.
 */
class UserOidcAvatarApiTest extends WebTestCase
{
    #[Group('NonThreadSafe')]
    public function testAnUploadIsMarkedAndRemovingItClearsTheMark(): void
    {
        $user = $this->oidcUser();
        $token = $this->token($user);

        $this->client->request('POST', '/api/users/avatar', files: ['uploadImage' => $this->upload()], server: ['HTTP_AUTHORIZATION' => $token]);

        self::assertResponseIsSuccessful();
        $this->assertTrue($this->reload($user)->oidcAvatarOverridden);

        $this->client->request('DELETE', '/api/users/avatar', server: ['HTTP_AUTHORIZATION' => $token]);

        self::assertResponseIsSuccessful();
        $user = $this->reload($user);
        $this->assertNull($user->avatar);
        $this->assertFalse($user->oidcAvatarOverridden);
    }

    public function testUploadAndRemovalAreRefusedWhenOverridesAreOff(): void
    {
        $this->settingsManager->set('MBIN_SSO_AVATAR_OVERRIDE', false);
        $user = $this->oidcUser();
        $avatarId = $user->avatar->getId();
        $token = $this->token($user);

        $this->client->request('POST', '/api/users/avatar', files: ['uploadImage' => $this->upload()], server: ['HTTP_AUTHORIZATION' => $token]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('DELETE', '/api/users/avatar', server: ['HTTP_AUTHORIZATION' => $token]);
        self::assertResponseStatusCodeSame(403);

        $user = $this->reload($user);
        $this->assertSame($avatarId, $user->avatar?->getId());
        $this->assertFalse($user->oidcAvatarOverridden);
    }

    #[Group('NonThreadSafe')]
    public function testALocalAccountMayStillUploadAndRemoveWhenOverridesAreOff(): void
    {
        $this->settingsManager->set('MBIN_SSO_AVATAR_OVERRIDE', false);
        $user = $this->getUserByUsername('JohnDoe');
        $token = $this->token($user);

        $this->client->request('POST', '/api/users/avatar', files: ['uploadImage' => $this->upload()], server: ['HTTP_AUTHORIZATION' => $token]);
        self::assertResponseIsSuccessful();
        $this->assertFalse($this->reload($user)->oidcAvatarOverridden);

        $this->client->request('DELETE', '/api/users/avatar', server: ['HTTP_AUTHORIZATION' => $token]);
        self::assertResponseIsSuccessful();
        $this->assertNull($this->reload($user)->avatar);
    }

    private function oidcUser(): User
    {
        $user = $this->getUserByUsername('JohnDoe');
        $user->oauthOidcId = 'oidc-john';
        $this->entityManager->flush();

        return $user;
    }

    private function token(User $user): string
    {
        self::createOAuth2AuthCodeClient();
        $this->client->loginUser($user);
        $codes = self::getAuthorizationCodeTokenResponse($this->client, scopes: 'read user:profile:edit user:profile:read');

        return $codes['token_type'].' '.$codes['access_token'];
    }

    /**
     * Uploading moves the file away, so upload a copy.
     */
    private function upload(): UploadedFile
    {
        $tmpPath = bin2hex(random_bytes(32)).'.png';
        copy($this->kibbyPath, $tmpPath);

        return new UploadedFile($tmpPath, 'kibby_emoji.png', 'image/png');
    }

    private function reload(User $user): User
    {
        $this->entityManager->clear();

        return $this->userRepository->find($user->getId());
    }
}
