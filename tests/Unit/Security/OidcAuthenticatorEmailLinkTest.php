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
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * An account is matched on email only when the provider vouches for that
 * email. Without this, anyone who can register an unverified address at the
 * provider could sign in as whichever member owns it.
 */
class OidcAuthenticatorEmailLinkTest extends TestCase
{
    public function testAVerifiedEmailLinksTheExistingAccount(): void
    {
        $existing = new User('member@example.test', 'member', 'irrelevant', 'Person');

        $user = $this->loadUser(['sub' => 'user-1', 'email' => 'member@example.test', 'email_verified' => true], $existing);

        self::assertSame($existing, $user);
        self::assertSame('user-1', $existing->oauthOidcId);
    }

    public function testAnUnverifiedEmailDoesNotLinkTheExistingAccount(): void
    {
        $existing = new User('member@example.test', 'member', 'irrelevant', 'Person');

        $this->expectException(CustomUserMessageAuthenticationException::class);

        try {
            $this->loadUser(['sub' => 'user-1', 'email' => 'member@example.test'], $existing);
        } finally {
            self::assertNull($existing->oauthOidcId);
        }
    }

    /**
     * @param array<string, mixed> $userinfo
     */
    private function loadUser(array $userinfo, User $existingByEmail): User
    {
        $token = new AccessToken(['access_token' => 'access', 'id_token' => 'id-token']);

        $client = $this->createStub(OidcClient::class);
        $client->method('consumeNonce')->willReturn('nonce');
        $client->method('getAccessToken')->willReturn($token);
        $client->method('fetchUserFromToken')->willReturn(new OidcResourceOwner($userinfo, 'preferred_username'));

        $validator = $this->createStub(OidcTokenValidator::class);
        $validator->method('validate')->willReturn(['sub' => $userinfo['sub']]);

        $byOidcId = $this->createStub(EntityRepository::class);
        $byOidcId->method('findOneBy')->willReturn(null);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($byOidcId);

        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findOneBy')->willReturn($existingByEmail);

        $groupClaimsResolver = new OidcMetadataResolver(new MockHttpClient([]), new ArrayAdapter(), 'https://idp.test', 'https://idp.test/a', 'https://idp.test/t', 'https://idp.test/u', 'https://idp.test/j');

        $authenticator = new OidcAuthenticator(
            $client,
            $validator,
            new OidcAdminGroupPolicy(null, new OidcGroupClaims($groupClaimsResolver)),
            new OidcMemberGroupPolicy(null, new OidcGroupClaims($groupClaimsResolver)),
            $entityManager,
            $this->createStub(UserManager::class),
            $this->createStub(ImageManagerInterface::class),
            $this->createStub(ImageFactory::class),
            $this->createStub(ImageRepository::class),
            $this->createStub(IpResolver::class),
            $this->createStub(Slugger::class),
            $userRepository,
            $this->createStub(SettingsManager::class),
            new NullLogger(),
            new RequestStack(),
            $this->createStub(RouterInterface::class),
        );

        $passport = $authenticator->authenticate(new Request());

        /** @var User $user */
        $user = $passport->getBadge(UserBadge::class)->getUser();

        return $user;
    }
}
