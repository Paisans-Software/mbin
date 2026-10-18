<?php

declare(strict_types=1);

namespace App\Security;

use App\DTO\UserDto;
use App\Entity\Image;
use App\Entity\User;
use App\Factory\ImageFactory;
use App\Provider\OidcResourceOwner;
use App\Repository\ImageRepository;
use App\Repository\UserRepository;
use App\Security\Oidc\OidcClient;
use App\Service\ImageManagerInterface;
use App\Service\IpResolver;
use App\Service\Oidc\Exception\OidcValidationException;
use App\Service\Oidc\OidcAdminGroupPolicy;
use App\Service\Oidc\OidcMemberGroupPolicy;
use App\Service\Oidc\OidcTokenValidator;
use App\Service\SettingsManager;
use App\Service\UserManager;
use App\Utils\Slugger;
use Doctrine\ORM\EntityManagerInterface;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;

class OidcAuthenticator extends MbinOAuthAuthenticatorBase
{
    /**
     * The message shown when a login is refused. It is deliberately vague: the
     * reason goes to the log, where an administrator can read it, and not to
     * the browser, where it would tell whoever is trying which check they
     * failed. MbinOAuthAuthenticatorBase renders this string directly rather
     * than translating it.
     */
    private const FAILURE_MESSAGE = 'Authentication failed.';

    /**
     * Request attribute set while loading the user and read back once the
     * login has succeeded. Admin cannot be granted while the user is being
     * loaded, because the user checker (banned, deleted, application still
     * pending) has not run yet and a refused login must leave nothing behind.
     */
    private const PROMOTE_ATTRIBUTE = 'oidc_admin_group_entitled';

    /**
     * Request attribute carrying the provider's picture URL for an account
     * that was already linked, read back once the login has succeeded. The
     * avatar is synced there rather than while loading the user for the same
     * reason as PROMOTE_ATTRIBUTE: a refused login must leave nothing behind.
     */
    private const PICTURE_ATTRIBUTE = 'oidc_picture_url';

    public function __construct(
        private readonly OidcClient $client,
        private readonly OidcTokenValidator $tokenValidator,
        private readonly OidcAdminGroupPolicy $adminGroupPolicy,
        private readonly OidcMemberGroupPolicy $memberGroupPolicy,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserManager $userManager,
        private readonly ImageManagerInterface $imageManager,
        private readonly ImageFactory $imageFactory,
        private readonly ImageRepository $imageRepository,
        private readonly IpResolver $ipResolver,
        private readonly Slugger $slugger,
        private readonly UserRepository $userRepository,
        private readonly SettingsManager $settingsManager,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
        RouterInterface $router,
    ) {
        parent::__construct($router);
    }

    public function supports(Request $request): ?bool
    {
        return 'oauth_oidc_verify' === $request->attributes->get('_route');
    }

    public function authenticate(Request $request): Passport
    {
        $expectedNonce = $this->client->consumeNonce();
        $accessToken = $this->client->getAccessToken();

        if (!$accessToken instanceof AccessToken) {
            $this->logger->error('OIDC login failed: the token endpoint returned an unusable token');

            throw new CustomUserMessageAuthenticationException(self::FAILURE_MESSAGE);
        }

        $idToken = $accessToken->getValues()['id_token'] ?? null;

        if (!\is_string($idToken) || '' === $idToken) {
            $this->logger->error('OIDC login failed: the token response carried no id_token');

            throw new CustomUserMessageAuthenticationException(self::FAILURE_MESSAGE);
        }

        try {
            $claims = $this->tokenValidator->validate($idToken, $expectedNonce);
        } catch (OidcValidationException $e) {
            $this->logger->warning('OIDC login failed: {reason}', ['reason' => $e->getMessage()]);

            throw new CustomUserMessageAuthenticationException(self::FAILURE_MESSAGE);
        }

        $rememberBadge = (new RememberMeBadge())->enable();

        return new SelfValidatingPassport(
            new UserBadge($accessToken->getToken(), function () use ($accessToken, $claims, $request) {
                /** @var OidcResourceOwner $oidcUser */
                $oidcUser = $this->client->fetchUserFromToken($accessToken);

                // The userinfo response and the id_token must describe the same
                // person. Without this, a token obtained for one subject could
                // be paired with another subject's profile.
                if (!hash_equals((string) $claims['sub'], (string) $oidcUser->getId())) {
                    $this->logger->error('OIDC login failed: the userinfo sub does not match the id_token sub');

                    throw new CustomUserMessageAuthenticationException(self::FAILURE_MESSAGE);
                }

                $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(
                    ['oauthOidcId' => $oidcUser->getId()]
                );

                // The admission gate, and the only check here that can refuse
                // somebody the provider was willing to issue a token to.
                //
                // Administrators of this instance are exempt, deliberately: a
                // provider that stops emitting the group claim would otherwise
                // lock out the only people who could put it back, and on an
                // MBIN_SSO_ONLY_MODE instance there is no password login to
                // recover through. The exemption reads the linked account, so
                // it cannot apply to a first login. That is correct: provisioning
                // a new account is exactly what the gate is for.
                //
                // Someone the admin group claim names is admitted too, first
                // login included. That claim promotes them as soon as the
                // login succeeds, so refusing them entry would contradict it,
                // and a provider's administrators need not also be listed in
                // the member group to reach the instance they administer.
                if (!$existingUser?->isAdmin()
                    && !$this->memberGroupPolicy->permits($claims, $oidcUser)
                    && !$this->adminGroupPolicy->shouldPromote($claims, $oidcUser)) {
                    $this->logger->warning('OIDC login refused: {subject} is not in {group}', [
                        'subject' => $oidcUser->getId(),
                        'group' => $this->memberGroupPolicy->group(),
                    ]);

                    throw new CustomUserMessageAuthenticationException(self::FAILURE_MESSAGE);
                }

                if ($existingUser) {
                    $this->rememberEntitlement($request, $existingUser, $claims, $oidcUser);
                    $this->rememberPicture($request, $oidcUser);

                    return $existingUser;
                }

                $email = $oidcUser->getEmail();

                if (null === $email) {
                    $this->logger->error('OIDC login failed: the provider returned no email claim');

                    throw new CustomUserMessageAuthenticationException(self::FAILURE_MESSAGE);
                }

                $user = $this->userRepository->findOneBy(['email' => $email]);

                if ($user) {
                    // Matching on email hands over an existing account, so
                    // the provider must vouch for the address. Otherwise
                    // anyone able to register an unverified address at the
                    // provider could sign in as whichever member owns it.
                    if (!$oidcUser->isEmailVerified()) {
                        $this->logger->error('OIDC login refused: the email matches an existing account but the provider did not mark it verified');

                        throw new CustomUserMessageAuthenticationException(self::FAILURE_MESSAGE);
                    }

                    $user->oauthOidcId = $oidcUser->getId();

                    $this->entityManager->persist($user);
                    $this->entityManager->flush();

                    $this->rememberEntitlement($request, $user, $claims, $oidcUser);
                    $this->rememberPicture($request, $oidcUser);

                    return $user;
                }

                if (false === $this->settingsManager->get('MBIN_SSO_REGISTRATIONS_ENABLED')) {
                    throw new CustomUserMessageAuthenticationException('MBIN_SSO_REGISTRATIONS_ENABLED');
                }

                $username = $this->slugger->slug((string) $oidcUser->getUsername());

                if ($this->userRepository->count(['username' => $username]) > 0) {
                    $username .= rand(1, 999);
                    $request->getSession()->set('is_newly_created', true);
                }

                $dto = (new UserDto())->create($username, $email);
                $dto->plainPassword = bin2hex(random_bytes(20));
                $dto->ip = $this->ipResolver->resolve();

                $avatar = $this->getAvatar($oidcUser->getPictureUrl());

                if ($avatar) {
                    $dto->avatar = $this->imageFactory->createDto($avatar);
                }

                $user = $this->userManager->create($dto, false);
                $user->oauthOidcId = $oidcUser->getId();
                $user->avatar = $avatar;
                $user->isVerified = true;

                $this->entityManager->persist($user);
                $this->entityManager->flush();

                $this->rememberEntitlement($request, $user, $claims, $oidcUser);

                return $user;
            }),
            [
                $rememberBadge,
            ]
        );
    }

    /**
     * Builds the token with ROLE_ADMIN when the provider placed this person
     * in the admin group, although the database is not promoted until
     * onAuthenticationSuccess. The firewall keeps this token's roles for the
     * whole session and never compares them again (User::isEqualTo leaves
     * roles out), so a token built from the unpromoted user would leave a
     * first login without admin until the next one. A login the user checker
     * refuses discards the token, so nothing is granted early.
     */
    public function createToken(Passport $passport, string $firewallName): TokenInterface
    {
        $token = parent::createToken($passport, $firewallName);

        if (true !== $this->requestStack->getCurrentRequest()?->attributes->get(self::PROMOTE_ATTRIBUTE)) {
            return $token;
        }

        $roles = array_values(array_unique([...$token->getRoleNames(), 'ROLE_ADMIN']));

        return new PostAuthenticationToken($passport->getUser(), $firewallName, $roles);
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();

        if ($user instanceof User && true === $request->attributes->get(self::PROMOTE_ATTRIBUTE)) {
            $this->promote($user);
        }

        $pictureUrl = $request->attributes->get(self::PICTURE_ATTRIBUTE);

        if ($user instanceof User && \is_string($pictureUrl)) {
            $this->syncAvatar($user, $pictureUrl);
        }

        return parent::onAuthenticationSuccess($request, $token, $firewallName);
    }

    /**
     * Records, for onAuthenticationSuccess, that the provider placed this
     * person in the configured admin group.
     *
     * @param array<string, mixed> $claims
     */
    private function rememberEntitlement(Request $request, User $user, array $claims, OidcResourceOwner $oidcUser): void
    {
        if ($user->isAdmin() || !$this->adminGroupPolicy->shouldPromote($claims, $oidcUser)) {
            return;
        }

        $request->attributes->set(self::PROMOTE_ATTRIBUTE, true);
    }

    /**
     * Records, for onAuthenticationSuccess, the picture the provider supplies
     * for an account that was already linked. A missing claim records
     * nothing, so the avatar is left as it is.
     */
    private function rememberPicture(Request $request, OidcResourceOwner $oidcUser): void
    {
        $pictureUrl = $oidcUser->getPictureUrl();

        if (null !== $pictureUrl) {
            $request->attributes->set(self::PICTURE_ATTRIBUTE, $pictureUrl);
        }
    }

    /**
     * Applies the provider's picture as the avatar, so that a member who
     * changes it at the provider sees the change at their next login. An
     * avatar the member uploaded themselves is kept while
     * MBIN_SSO_AVATAR_OVERRIDE allows that.
     *
     * The change goes through UserManager::edit so that it federates and the
     * replaced image is cleaned up. Nothing here may fail the login: the
     * member has already been authenticated, and a picture that cannot be
     * fetched is only logged.
     */
    private function syncAvatar(User $user, string $pictureUrl): void
    {
        $overrideAllowed = $this->settingsManager->isSsoAvatarOverrideAllowed();

        if ($overrideAllowed && $user->oidcAvatarOverridden) {
            return;
        }

        try {
            $avatar = $this->getAvatar($pictureUrl);

            // The image repository dedupes by hash, so an unchanged picture
            // resolves to the image the member already has.
            if (null === $avatar || $avatar === $user->avatar) {
                return;
            }

            $dto = $this->userManager->createDto($user);
            $dto->avatar = $this->imageFactory->createDto($avatar);

            if (!$overrideAllowed) {
                $user->oidcAvatarOverridden = false;
            }

            $this->userManager->edit($user, $dto);
        } catch (\Throwable $e) {
            $this->logger->warning('OIDC avatar sync failed for {username}: {reason}', [
                'username' => $user->getUserIdentifier(),
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Grants ROLE_ADMIN. Never removes it: see OidcAdminGroupPolicy for why
     * taking admin away on a claim's absence is the more dangerous of the two
     * mistakes.
     */
    private function promote(User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $user->setOrRemoveAdminRole();

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->logger->notice('Granted ROLE_ADMIN to {username}: the OIDC provider placed them in {group}', [
            'username' => $user->getUserIdentifier(),
            'group' => $this->adminGroupPolicy->group(),
        ]);
    }

    private function getAvatar(?string $pictureUrl): ?Image
    {
        if (!$pictureUrl) {
            return null;
        }

        try {
            $tempFile = $this->imageManager->download($pictureUrl);
        } catch (\Exception) {
            return null;
        }

        if (!$tempFile) {
            return null;
        }

        $image = $this->imageRepository->findOrCreateFromPath($tempFile);

        if ($image) {
            $this->entityManager->persist($image);
            $this->entityManager->flush();
        }

        return $image;
    }
}
