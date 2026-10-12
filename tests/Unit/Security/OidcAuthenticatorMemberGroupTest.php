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
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * The gate runs while the user is being loaded, which is the only point that
 * covers both an existing account and one about to be provisioned. A refusal
 * therefore has to happen before anything is written, not after.
 */
class OidcAuthenticatorMemberGroupTest extends TestCase
{
    public function testAMemberOfTheGroupIsAdmitted(): void
    {
        $user = $this->user();

        self::assertSame($user, $this->loadUser($user, ['groups' => ['members']]));
    }

    public function testSomeoneOutsideTheGroupIsRefused(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);

        $this->loadUser($this->user(), ['groups' => ['other']]);
    }

    public function testEveryoneIsAdmittedWhenNoMemberGroupIsConfigured(): void
    {
        $user = $this->user();

        self::assertSame($user, $this->loadUser($user, ['groups' => ['other']], memberGroup: null));
    }

    /**
     * The exemption the admin policy's own asymmetry argues for: a provider
     * that stops emitting the claim must not lock the operators out of their
     * own instance, on an SSO-only instance where there is no password login
     * to fall back on.
     */
    public function testAnExistingAdminIsAdmittedWithoutTheGroup(): void
    {
        $user = $this->user()->setOrRemoveAdminRole();

        self::assertSame($user, $this->loadUser($user, ['groups' => ['other']]));
    }

    public function testAnExistingAdminIsAdmittedWhenTheClaimIsMissingEntirely(): void
    {
        $user = $this->user()->setOrRemoveAdminRole();

        self::assertSame($user, $this->loadUser($user, []));
    }

    /**
     * A first login has no linked account to read an admin flag from, so the
     * exemption cannot apply to one. Provisioning is exactly what the gate is
     * for, and refusing here is what stops an account being created at all.
     */
    public function testAnUnlinkedLoginIsRefusedWithoutTheGroup(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);

        $this->loadUser(null, ['groups' => ['other']]);
    }

    /**
     * The admin group claim would promote this person as soon as the login
     * succeeds, so refusing them entry contradicts it. Admitting them here is
     * what lets a provider's administrators make their first login before
     * anyone has added them to the member group.
     */
    public function testAnUnlinkedLoginInTheAdminGroupIsAdmitted(): void
    {
        // Registration is off in these cases, so getting as far as that check
        // is what shows the gate let the login through.
        $this->expectExceptionObject(new CustomUserMessageAuthenticationException('MBIN_SSO_REGISTRATIONS_ENABLED'));

        $this->loadUser(null, ['groups' => ['admins']], adminGroup: 'admins');
    }

    public function testTheAdminGroupAdmitsNobodyWhenItIsNotConfigured(): void
    {
        $this->expectException(CustomUserMessageAuthenticationException::class);

        $this->loadUser(null, ['groups' => ['admins']]);
    }

    private function user(): User
    {
        $user = new User('member@example.test', 'member', 'irrelevant', 'Person');
        $user->oauthOidcId = 'user-1';

        return $user;
    }

    /**
     * Runs authenticate() and resolves the user the way the firewall would.
     *
     * @param array<string, mixed> $idTokenClaims
     */
    private function loadUser(?User $user, array $idTokenClaims, ?string $memberGroup = 'members', ?string $adminGroup = null): ?object
    {
        $token = new AccessToken(['access_token' => 'access', 'id_token' => 'id-token']);

        $client = $this->createStub(OidcClient::class);
        $client->method('consumeNonce')->willReturn('nonce');
        $client->method('getAccessToken')->willReturn($token);
        $client->method('fetchUserFromToken')->willReturn(new OidcResourceOwner(['sub' => 'user-1', 'email' => 'member@example.test'], 'preferred_username'));

        $validator = $this->createStub(OidcTokenValidator::class);
        $validator->method('validate')->willReturn($idTokenClaims + ['sub' => 'user-1']);

        $byOidcId = $this->createStub(EntityRepository::class);
        $byOidcId->method('findOneBy')->willReturn($user);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($byOidcId);

        // Plain http, so an unsigned userinfo response can never stand in for
        // the id_token claim in these cases.
        $resolver = new OidcMetadataResolver(
            new MockHttpClient([]),
            new ArrayAdapter(),
            'https://idp.test',
            'https://idp.test/authorize',
            'https://idp.test/token',
            'http://idp:8080/userinfo',
            'https://idp.test/jwks',
        );
        $groupClaims = new OidcGroupClaims($resolver);

        $settings = $this->createStub(SettingsManager::class);
        $settings->method('get')->willReturn(false);

        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturn('/');

        $authenticator = new OidcAuthenticator(
            $client,
            $validator,
            new OidcAdminGroupPolicy($adminGroup, $groupClaims),
            new OidcMemberGroupPolicy($memberGroup, $groupClaims),
            $entityManager,
            $this->createStub(UserManager::class),
            $this->createStub(ImageManagerInterface::class),
            $this->createStub(ImageFactory::class),
            $this->createStub(ImageRepository::class),
            $this->createStub(IpResolver::class),
            $this->createStub(Slugger::class),
            $this->createStub(UserRepository::class),
            $settings,
            new NullLogger(),
            $router,
        );

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $authenticator->authenticate($request)->getBadge(UserBadge::class)->getUser();
    }
}
