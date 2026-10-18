<?php

declare(strict_types=1);

namespace App\Tests\Functional\Controller\User\Profile;

use App\Entity\User;
use App\Tests\WebTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\DomCrawler\Field\FileFormField;

/**
 * A member linked to the OIDC provider who uploads an avatar keeps it over
 * the provider's picture, and removing it hands the avatar back to the
 * provider. With MBIN_SSO_AVATAR_OVERRIDE off, such a member cannot do
 * either. Local accounts are not affected by the setting.
 */
class UserOidcAvatarTest extends WebTestCase
{
    #[Group('NonThreadSafe')]
    public function testAnUploadMarksTheAvatarAsTheMembersOwn(): void
    {
        $user = $this->oidcUser();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/settings/profile');
        $form = $crawler->filter('#main form[name=user_basic]')->selectButton('Save')->form();
        $field = $form['user_basic[avatar]'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($this->kibbyPath);
        $this->client->submit($form);

        $user = $this->reload($user);
        $this->assertStringContainsString(self::KIBBY_PNG_URL_RESULT, $user->avatar->filePath);
        $this->assertTrue($user->oidcAvatarOverridden);
    }

    public function testSavingTheProfileWithoutAnUploadLeavesTheMarkAlone(): void
    {
        $user = $this->oidcUser();
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/settings/profile');
        $this->client->submit($crawler->filter('#main form[name=user_basic]')->selectButton('Save')->form([
            'user_basic[about]' => 'about me',
        ]));

        $this->assertFalse($this->reload($user)->oidcAvatarOverridden);
    }

    public function testRemovingTheAvatarHandsItBackToTheProvider(): void
    {
        $user = $this->oidcUser();
        $user->oidcAvatarOverridden = true;
        $this->entityManager->flush();
        $this->client->loginUser($user);

        $this->client->request('POST', '/settings/edit/delete_avatar');

        $user = $this->reload($user);
        $this->assertNull($user->avatar);
        $this->assertFalse($user->oidcAvatarOverridden);
    }

    public function testTheFormOffersNoAvatarWhenOverridesAreOff(): void
    {
        $this->settingsManager->set('MBIN_SSO_AVATAR_OVERRIDE', false);
        $this->client->loginUser($this->oidcUser());

        $this->client->request('GET', '/settings/profile');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="user_basic[avatar]"]');
        self::assertSelectorNotExists('button[formaction$="/settings/edit/delete_avatar"]');
        self::assertSelectorExists('input[name="user_basic[cover]"]');
    }

    public function testRemovingTheAvatarIsRefusedWhenOverridesAreOff(): void
    {
        $this->settingsManager->set('MBIN_SSO_AVATAR_OVERRIDE', false);
        $user = $this->oidcUser();
        $avatarId = $user->avatar->getId();
        $this->client->loginUser($user);

        $this->client->request('POST', '/settings/edit/delete_avatar');

        self::assertResponseStatusCodeSame(403);
        $this->assertSame($avatarId, $this->reload($user)->avatar?->getId());
    }

    public function testALocalAccountKeepsItsAvatarControlsWhenOverridesAreOff(): void
    {
        $this->settingsManager->set('MBIN_SSO_AVATAR_OVERRIDE', false);
        $user = $this->getUserByUsername('JohnDoe');
        $this->client->loginUser($user);

        $this->client->request('GET', '/settings/profile');
        self::assertSelectorExists('input[name="user_basic[avatar]"]');
        self::assertSelectorExists('button[formaction$="/settings/edit/delete_avatar"]');

        $this->client->request('POST', '/settings/edit/delete_avatar');

        $user = $this->reload($user);
        $this->assertNull($user->avatar);
        $this->assertFalse($user->oidcAvatarOverridden);
    }

    #[Group('NonThreadSafe')]
    public function testALocalAccountUploadIsNotMarked(): void
    {
        $user = $this->getUserByUsername('JohnDoe');
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/settings/profile');
        $form = $crawler->filter('#main form[name=user_basic]')->selectButton('Save')->form();
        $field = $form['user_basic[avatar]'];
        self::assertInstanceOf(FileFormField::class, $field);
        $field->upload($this->kibbyPath);
        $this->client->submit($form);

        $user = $this->reload($user);
        $this->assertStringContainsString(self::KIBBY_PNG_URL_RESULT, $user->avatar->filePath);
        $this->assertFalse($user->oidcAvatarOverridden);
    }

    private function oidcUser(): User
    {
        $user = $this->getUserByUsername('JohnDoe');
        $user->oauthOidcId = 'oidc-john';
        $this->entityManager->flush();

        return $user;
    }

    private function reload(User $user): User
    {
        $this->entityManager->clear();

        return $this->userRepository->find($user->getId());
    }
}
