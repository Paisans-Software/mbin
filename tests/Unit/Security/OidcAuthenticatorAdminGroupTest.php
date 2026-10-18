<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

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
 * Admin is granted only once the login has actually succeeded. The user
 * checker (banned, deleted, application pending) runs between loading the
 * user and onAuthenticationSuccess, so a refused login must leave no
 * ROLE_ADMIN behind in the database.
 */
class OidcAuthenticatorAdminGroupTest extends TestCase
{
    public function testAMemberOfTheGroupBecomesAdminWhenTheLoginSucceeds(): void
    {
        $user = $this->user();
        [$authenticator, $request, $passport] = $this->authenticate($user, ['groups' => ['mbin-admins']]);

        self::assertFalse($user->isAdmin(), 'loading the user must not grant admin yet');

        $authenticator->onAuthenticationSuccess($request, $this->token($passport), 'main');

        self::assertTrue($user->isAdmin());
    }

    /**
     * The firewall stores the token createToken returns, with the roles it was
     * built with, and never compares roles again (User is an
     * EquatableInterface that leaves them out). A token built from the
     * unpromoted user would keep a first login without admin for the whole
     * session.
     */
    public function testTheTokenCarriesAdminFromTheFirstLogin(): void
    {
        $user = $this->user();
        [$authenticator, , $passport] = $this->authenticate($user, ['groups' => ['mbin-admins']]);

        $token = $authenticator->createToken($passport, 'main');

        self::assertContains('ROLE_ADMIN', $token->getRoleNames());
        self::assertFalse($user->isAdmin(), 'building the token must not grant admin in the database');
    }

    public function testTheTokenOfSomeoneOutsideTheGroupCarriesNoAdmin(): void
    {
        $user = $this->user();
        [$authenticator, , $passport] = $this->authenticate($user, ['groups' => ['members']]);

        $token = $authenticator->createToken($passport, 'main');

        self::assertNotContains('ROLE_ADMIN', $token->getRoleNames());
    }

    public function testARefusedLoginLeavesNoAdminBehind(): void
    {
        $user = $this->user();
        $this->authenticate($user, ['groups' => ['mbin-admins']]);

        // No onAuthenticationSuccess: the user checker threw.
        self::assertFalse($user->isAdmin());
    }

    public function testSomeoneOutsideTheGroupIsNotPromoted(): void
    {
        $user = $this->user();
        [$authenticator, $request, $passport] = $this->authenticate($user, ['groups' => ['members']]);

        $authenticator->onAuthenticationSuccess($request, $this->token($passport), 'main');

        self::assertFalse($user->isAdmin());
    }

    public function testNothingHappensWhenNoGroupIsConfigured(): void
    {
        $user = $this->user();
        [$authenticator, $request, $passport] = $this->authenticate($user, ['groups' => ['mbin-admins']], adminGroup: null);

        $authenticator->onAuthenticationSuccess($request, $this->token($passport), 'main');

        self::assertFalse($user->isAdmin());
    }

    public function testAnExistingAdminIsLeftAlone(): void
    {
        $user = $this->user()->setOrRemoveAdminRole();
        [$authenticator, $request, $passport] = $this->authenticate($user, ['groups' => ['mbin-admins']]);

        $authenticator->onAuthenticationSuccess($request, $this->token($passport), 'main');

        self::assertTrue($user->isAdmin());
    }

    private function user(): User
    {
        $user = new User('member@example.test', 'member', 'irrelevant', 'Person');
        $user->oauthOidcId = 'user-1';

        return $user;
    }

    private function token(Passport $passport): UsernamePasswordToken
    {
        /** @var User $user */
        $user = $passport->getBadge(UserBadge::class)->getUser();

        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    /**
     * Runs authenticate() and resolves the user the way the firewall would,
     * for a member already linked by oauth_oidc_id.
     *
     * @param array<string, mixed> $idTokenClaims
     *
     * @return array{OidcAuthenticator, Request, Passport}
     */
    private function authenticate(User $user, array $idTokenClaims, ?string $adminGroup = 'mbin-admins'): array
    {
        $token = new AccessToken(['access_token' => 'access', 'id_token' => 'id-token']);

        $client = $this->createStub(OidcClient::class);
        $client->method('consumeNonce')->willReturn('nonce');
        $client->method('getAccessToken')->willReturn($token);
        $client->method('fetchUserFromToken')->willReturn(new OidcResourceOwner(['sub' => 'user-1'], 'preferred_username'));

        $validator = $this->createStub(OidcTokenValidator::class);
        $validator->method('validate')->willReturn($idTokenClaims + ['sub' => 'user-1']);

        $byOidcId = $this->createStub(EntityRepository::class);
        $byOidcId->method('findOneBy')->willReturn($user);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($byOidcId);

        $resolver = new OidcMetadataResolver(
            new MockHttpClient([]),
            new ArrayAdapter(),
            'https://idp.test',
            'https://idp.test/authorize',
            'https://idp.test/token',
            'https://idp.test/userinfo',
            'https://idp.test/jwks',
        );

        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturn('/');

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $authenticator = new OidcAuthenticator(
            $client,
            $validator,
            new OidcAdminGroupPolicy($adminGroup, new OidcGroupClaims($resolver)),
            new OidcMemberGroupPolicy(null, new OidcGroupClaims($resolver)),
            $entityManager,
            $this->createStub(UserManager::class),
            $this->createStub(ImageManagerInterface::class),
            $this->createStub(ImageFactory::class),
            $this->createStub(ImageRepository::class),
            $this->createStub(IpResolver::class),
            $this->createStub(Slugger::class),
            $this->createStub(UserRepository::class),
            $this->createStub(SettingsManager::class),
            new NullLogger(),
            $requestStack,
            $router,
        );

        $passport = $authenticator->authenticate($request);
        $passport->getBadge(UserBadge::class)->getUser();

        return [$authenticator, $request, $passport];
    }
}
