<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\DTO\ImageDto;
use App\DTO\UserDto;
use App\Entity\Image;
use App\Entity\User;
use App\Factory\ImageFactory;
use App\Provider\OidcResourceOwner;
use App\Repository\ImageRepository;
use App\Repository\UserRepository;
use App\Security\Oidc\OidcClient;
use App\Security\OidcAuthenticator;
use App\Service\ImageManagerInterface;
use App\Service\IpResolver;
use App\Service\Oidc\OidcAdminGroupPolicy;
use App\Service\Oidc\OidcGroupClaims;
use App\Service\Oidc\OidcMemberGroupPolicy;
use App\Service\Oidc\OidcMetadataResolver;
use App\Service\Oidc\OidcTokenValidator;
use App\Service\SettingsManager;
use App\Service\UserManager;
use App\Utils\Slugger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;

/**
 * An account linked to the OIDC provider takes the provider's picture again
 * at every login, once the login has succeeded. A member's own upload is
 * kept while MBIN_SSO_AVATAR_OVERRIDE allows it.
 */
class OidcAuthenticatorAvatarSyncTest extends TestCase
{
    private const string PICTURE = 'https://idp.test/picture/user-1.png';

    /** @var string[] the URLs a download was attempted from */
    private array $downloads = [];

    /** @var UserDto[] what UserManager::edit was handed */
    private array $edited = [];

    /** What the picture resolves to, or the exception the download throws. */
    private Image|\Throwable|null $download = null;

    /** The exception processing the downloaded file throws, if any. */
    private ?\Throwable $processing = null;

    /** The exception saving the user throws, if any. */
    private ?\Throwable $saving = null;

    public function testAChangedPictureReplacesTheAvatarWhenTheLoginSucceeds(): void
    {
        $user = $this->user(avatar: $this->image('a'));
        $this->download = $this->image('b');

        [$authenticator, $request, $passport] = $this->authenticate($user);
        self::assertSame([], $this->edited, 'loading the user must not touch the avatar yet');

        $authenticator->onAuthenticationSuccess($request, $this->token($passport), 'main');

        self::assertSame([self::PICTURE], $this->downloads);
        self::assertCount(1, $this->edited);
        self::assertInstanceOf(ImageDto::class, $this->edited[0]->avatar);
    }

    public function testTheVerifiedEmailLinkPathSyncsToo(): void
    {
        $user = $this->user(avatar: $this->image('a'));
        $user->oauthOidcId = null;
        $this->download = $this->image('b');

        [$authenticator, $request, $passport] = $this->authenticate($user, linkByEmail: true);
        $authenticator->onAuthenticationSuccess($request, $this->token($passport), 'main');

        self::assertSame('user-1', $user->oauthOidcId);
        self::assertCount(1, $this->edited);
    }

    public function testAnIdenticalPictureLeavesTheAvatarAlone(): void
    {
        $avatar = $this->image('a');
        $user = $this->user(avatar: $avatar);
        // findOrCreateFromPath dedupes by hash, so the same picture is the same Image.
        $this->download = $avatar;

        [$authenticator, $request, $passport] = $this->authenticate($user);
        $authenticator->onAuthenticationSuccess($request, $this->token($passport), 'main');

        self::assertSame([], $this->edited);
    }

    public function testAMissingPictureClaimLeavesTheAvatarAlone(): void
    {
        $user = $this->user(avatar: $this->image('a'));

        [$authenticator, $request, $passport] = $this->authenticate($user, picture: null);
        $authenticator->onAuthenticationSuccess($request, $this->token($passport), 'main');

        self::assertSame([], $this->downloads);
        self::assertSame([], $this->edited);
    }

    public function testAnUploadTheMemberChoseIsKeptWhileTheSettingAllowsIt(): void
    {
        $user = $this->user(avatar: $this->image('a'));
        $user->oidcAvatarOverridden = true;

        [$authenticator, $request, $passport] = $this->authenticate($user, overrideAllowed: true);
        $authenticator->onAuthenticationSuccess($request, $this->token($passport), 'main');

        self::assertSame([], $this->downloads);
        self::assertSame([], $this->edited);
        self::assertTrue($user->oidcAvatarOverridden);
    }

    public function testThePictureReplacesAnUploadWhenTheSettingForbidsOverrides(): void
    {
        $user = $this->user(avatar: $this->image('a'));
        $user->oidcAvatarOverridden = true;
        $this->download = $this->image('b');

        [$authenticator, $request, $passport] = $this->authenticate($user, overrideAllowed: false);
        $authenticator->onAuthenticationSuccess($request, $this->token($passport), 'main');

        self::assertCount(1, $this->edited);
        self::assertFalse($user->oidcAvatarOverridden);
    }

    public function testAFailedDownloadDoesNotFailTheLogin(): void
    {
        $user = $this->user(avatar: $this->image('a'));
        $this->download = new \RuntimeException('unreachable');

        [$authenticator, $request, $passport] = $this->authenticate($user);
        $response = $authenticator->onAuthenticationSuccess($request, $this->token($passport), 'main');

        self::assertNotNull($response);
        self::assertSame([], $this->edited);
    }

    public function testAnImageThatCannotBeProcessedDoesNotFailTheLogin(): void
    {
        $user = $this->user(avatar: $this->image('a'));
        $this->download = $this->image('b');
        $this->processing = new \RuntimeException('not an image');

        [$authenticator, $request, $passport] = $this->authenticate($user);
        $response = $authenticator->onAuthenticationSuccess($request, $this->token($passport), 'main');

        self::assertNotNull($response);
        self::assertSame([], $this->edited);
    }

    public function testAFailedSaveDoesNotFailTheLogin(): void
    {
        $user = $this->user(avatar: $this->image('a'));
        $this->download = $this->image('b');
        $this->saving = new \RuntimeException('database gone');

        [$authenticator, $request, $passport] = $this->authenticate($user);
        $response = $authenticator->onAuthenticationSuccess($request, $this->token($passport), 'main');

        self::assertNotNull($response);
    }

    public function testARefusedLoginLeavesTheAvatarUntouched(): void
    {
        $avatar = $this->image('a');
        $user = $this->user(avatar: $avatar);

        $this->authenticate($user);

        // No onAuthenticationSuccess: the user checker threw.
        self::assertSame($avatar, $user->avatar);
        self::assertSame([], $this->downloads);
        self::assertSame([], $this->edited);
    }

    private function image(string $name): Image
    {
        return new Image($name.'.png', 'a/b/'.$name.'.png', hash('sha256', $name), 10, 10, null);
    }

    private function user(?Image $avatar): User
    {
        $user = new User('member@example.test', 'member', 'irrelevant', 'Person');
        $user->oauthOidcId = 'user-1';
        $user->avatar = $avatar;

        return $user;
    }

    private function token(Passport $passport): UsernamePasswordToken
    {
        /** @var User $user */
        $user = $passport->getBadge(UserBadge::class)->getUser();

        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    /**
     * Runs authenticate() and resolves the user the way the firewall would.
     *
     * @return array{OidcAuthenticator, Request, Passport}
     */
    private function authenticate(User $user, ?string $picture = self::PICTURE, bool $overrideAllowed = true, bool $linkByEmail = false): array
    {
        $token = new AccessToken(['access_token' => 'access', 'id_token' => 'id-token']);

        $userinfo = ['sub' => 'user-1', 'email' => 'member@example.test', 'email_verified' => true];
        if (null !== $picture) {
            $userinfo['picture'] = $picture;
        }

        $client = $this->createStub(OidcClient::class);
        $client->method('consumeNonce')->willReturn('nonce');
        $client->method('getAccessToken')->willReturn($token);
        $client->method('fetchUserFromToken')->willReturn(new OidcResourceOwner($userinfo, 'preferred_username'));

        $validator = $this->createStub(OidcTokenValidator::class);
        $validator->method('validate')->willReturn(['sub' => 'user-1']);

        $byOidcId = $this->createStub(EntityRepository::class);
        $byOidcId->method('findOneBy')->willReturn($linkByEmail ? null : $user);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($byOidcId);

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findOneBy')->willReturn($linkByEmail ? $user : null);

        $settingsManager = $this->createStub(SettingsManager::class);
        $settingsManager->method('isSsoAvatarOverrideAllowed')->willReturn($overrideAllowed);

        $imageManager = $this->createStub(ImageManagerInterface::class);
        $imageManager->method('download')->willReturnCallback(function (string $url) {
            $this->downloads[] = $url;

            if ($this->download instanceof \Throwable) {
                throw $this->download;
            }

            return null === $this->download ? null : '/tmp/picture';
        });

        $imageRepository = $this->createStub(ImageRepository::class);
        $imageRepository->method('findOrCreateFromPath')->willReturnCallback(function () {
            if (null !== $this->processing) {
                throw $this->processing;
            }

            return $this->download instanceof Image ? $this->download : null;
        });

        $imageFactory = $this->createStub(ImageFactory::class);
        $imageFactory->method('createDto')->willReturnCallback(fn () => new ImageDto());

        $userManager = $this->createStub(UserManager::class);
        $userManager->method('createDto')->willReturnCallback(fn () => new UserDto());
        $userManager->method('edit')->willReturnCallback(function (User $user, UserDto $dto) {
            if (null !== $this->saving) {
                throw $this->saving;
            }

            $this->edited[] = $dto;

            return $user;
        });

        $resolver = new OidcMetadataResolver(new MockHttpClient([]), new ArrayAdapter(), 'https://idp.test', 'https://idp.test/a', 'https://idp.test/t', 'https://idp.test/u', 'https://idp.test/j');

        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturn('/');

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $authenticator = new OidcAuthenticator(
            $client,
            $validator,
            new OidcAdminGroupPolicy(null, new OidcGroupClaims($resolver)),
            new OidcMemberGroupPolicy(null, new OidcGroupClaims($resolver)),
            $entityManager,
            $userManager,
            $imageManager,
            $imageFactory,
            $imageRepository,
            $this->createStub(IpResolver::class),
            $this->createStub(Slugger::class),
            $userRepository,
            $settingsManager,
            new NullLogger(),
            $requestStack,
            $router,
        );

        $passport = $authenticator->authenticate($request);
        $passport->getBadge(UserBadge::class)->getUser();

        return [$authenticator, $request, $passport];
    }
}
