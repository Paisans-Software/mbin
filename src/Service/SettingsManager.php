<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\SettingsDto;
use App\Entity\Instance;
use App\Entity\Settings;
use App\Repository\InstanceRepository;
use App\Repository\SettingsRepository;
use App\Utils\DownvotesMode;
use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\Pure;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Contracts\Service\ResetInterface;

class SettingsManager implements ResetInterface
{
    /**
     * The settings that fall back to an environment variable when the database
     * holds no value, which are the only ones MBIN_LOCKED_SETTINGS may name.
     * A locked setting always reads that environment value.
     */
    public const array LOCKABLE_SETTINGS = [
        'KBIN_DOMAIN',
        'KBIN_TITLE',
        'KBIN_META_TITLE',
        'KBIN_META_KEYWORDS',
        'KBIN_META_DESCRIPTION',
        'KBIN_DEFAULT_LANG',
        'KBIN_CONTACT_EMAIL',
        'KBIN_SENDER_EMAIL',
        'MBIN_DEFAULT_THEME',
        'KBIN_JS_ENABLED',
        'KBIN_FEDERATION_ENABLED',
        'KBIN_REGISTRATIONS_ENABLED',
        'KBIN_HEADER_LOGO',
        'KBIN_CAPTCHA_ENABLED',
        'KBIN_FEDERATION_PAGE_ENABLED',
        'KBIN_ADMIN_ONLY_OAUTH_CLIENTS',
        'MBIN_SSO_ONLY_MODE',
        'MBIN_PRIVATE_INSTANCE',
        'MBIN_DOWNVOTES_MODE',
        'MBIN_NEW_USERS_NEED_APPROVAL',
        'MBIN_USE_FEDERATION_ALLOW_LIST',
        'MBIN_AUTHORIZED_FETCH',
        'MBIN_SSO_AVATAR_OVERRIDE',
        'KBIN_MERCURE_ENABLED',
        'KBIN_FEDERATED_SEARCH_ONLY_LOGGEDIN',
        'MBIN_SIDEBAR_SECTIONS_RANDOM_LOCAL_ONLY',
        'MBIN_SIDEBAR_SECTIONS_USERS_LOCAL_ONLY',
        'MBIN_SSO_REGISTRATIONS_ENABLED',
        'MBIN_RESTRICT_MAGAZINE_CREATION',
        'MBIN_SSO_SHOW_FIRST',
    ];

    /**
     * Loaded on first use and cleared by reset(), which the kernel calls after every
     * request and the messenger worker after every message, so that a long-running
     * worker picks up settings that were saved by another process.
     */
    private ?SettingsDto $instanceDto = null;

    /** @var string[] */
    private readonly array $lockedSettings;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SettingsRepository $repository,
        private readonly RequestStack $requestStack,
        private readonly InstanceRepository $instanceRepository,
        private readonly string $kbinDomain,
        private readonly string $kbinTitle,
        private readonly string $kbinMetaTitle,
        private readonly string $kbinMetaDescription,
        private readonly string $kbinMetaKeywords,
        private readonly string $kbinDefaultLang,
        private readonly string $kbinContactEmail,
        private readonly string $kbinSenderEmail,
        private readonly string $mbinDefaultTheme,
        private readonly bool $kbinJsEnabled,
        private readonly bool $kbinFederationEnabled,
        private readonly bool $kbinRegistrationsEnabled,
        private readonly bool $kbinHeaderLogo,
        private readonly bool $kbinCaptchaEnabled,
        private readonly bool $kbinFederationPageEnabled,
        private readonly bool $kbinAdminOnlyOauthClients,
        private readonly bool $mbinSsoOnlyMode,
        private readonly int $mbinMaxImageBytes,
        private readonly DownvotesMode $mbinDownvotesMode,
        private readonly bool $mbinNewUsersNeedApproval,
        private readonly LoggerInterface $logger,
        private readonly bool $mbinUseFederationAllowList,
        private readonly bool $mbinAuthorizedFetch,
        private readonly string $mbinSearchLang,
        private readonly bool $mbinPrivateInstance,
        private readonly bool $kbinMercureEnabled,
        private readonly bool $kbinFederatedSearchOnlyLoggedin,
        private readonly bool $mbinSidebarSectionsRandomLocalOnly,
        private readonly bool $mbinSidebarSectionsUsersLocalOnly,
        private readonly bool $mbinSsoRegistrationsEnabled,
        private readonly bool $mbinRestrictMagazineCreation,
        private readonly bool $mbinSsoShowFirst,
        string $mbinLockedSettings = '',
        private readonly bool $mbinSsoAvatarOverride = true,
    ) {
        $this->lockedSettings = self::parseLockedSettings($mbinLockedSettings);
    }

    public function reset(): void
    {
        $this->instanceDto = null;
    }

    private function load(): SettingsDto
    {
        // A locked setting ignores any row the database still holds for it.
        $results = array_filter(
            $this->repository->findAllIndexedByName(),
            fn (Settings $s) => !$this->isLocked($s->name),
        );

        $newUsersNeedApprovalDb = $this->find($results, 'MBIN_NEW_USERS_NEED_APPROVAL');
        if ('true' === $newUsersNeedApprovalDb) {
            $newUsersNeedApprovalEdited = true;
        } elseif ('false' === $newUsersNeedApprovalDb) {
            $newUsersNeedApprovalEdited = false;
        } else {
            $newUsersNeedApprovalEdited = $this->mbinNewUsersNeedApproval;
        }

        return new SettingsDto(
            $this->kbinDomain,
            $this->find($results, 'KBIN_TITLE') ?? $this->kbinTitle,
            $this->find($results, 'KBIN_META_TITLE') ?? $this->kbinMetaTitle,
            $this->find($results, 'KBIN_META_KEYWORDS') ?? $this->kbinMetaKeywords,
            $this->find($results, 'KBIN_META_DESCRIPTION') ?? $this->kbinMetaDescription,
            $this->find($results, 'KBIN_DEFAULT_LANG') ?? $this->kbinDefaultLang,
            $this->find($results, 'KBIN_CONTACT_EMAIL') ?? $this->kbinContactEmail,
            $this->find($results, 'KBIN_SENDER_EMAIL') ?? $this->kbinSenderEmail,
            $this->find($results, 'MBIN_DEFAULT_THEME') ?? $this->mbinDefaultTheme,
            $this->find($results, 'KBIN_JS_ENABLED', FILTER_VALIDATE_BOOLEAN) ?? $this->kbinJsEnabled,
            $this->find(
                $results,
                'KBIN_FEDERATION_ENABLED',
                FILTER_VALIDATE_BOOLEAN
            ) ?? $this->kbinFederationEnabled,
            $this->find(
                $results,
                'KBIN_REGISTRATIONS_ENABLED',
                FILTER_VALIDATE_BOOLEAN
            ) ?? $this->kbinRegistrationsEnabled,
            $this->find($results, 'KBIN_HEADER_LOGO', FILTER_VALIDATE_BOOLEAN) ?? $this->kbinHeaderLogo,
            $this->find($results, 'KBIN_CAPTCHA_ENABLED', FILTER_VALIDATE_BOOLEAN) ?? $this->kbinCaptchaEnabled,
            $this->find($results, 'KBIN_MERCURE_ENABLED', FILTER_VALIDATE_BOOLEAN) ?? $this->kbinMercureEnabled,
            $this->find($results, 'KBIN_FEDERATION_PAGE_ENABLED', FILTER_VALIDATE_BOOLEAN) ?? $this->kbinFederationPageEnabled,
            $this->find($results, 'KBIN_ADMIN_ONLY_OAUTH_CLIENTS', FILTER_VALIDATE_BOOLEAN) ?? $this->kbinAdminOnlyOauthClients,
            $this->find($results, 'MBIN_SSO_ONLY_MODE', FILTER_VALIDATE_BOOLEAN) ?? $this->mbinSsoOnlyMode,
            $this->find($results, 'MBIN_PRIVATE_INSTANCE', FILTER_VALIDATE_BOOLEAN) ?? $this->mbinPrivateInstance,
            $this->find($results, 'KBIN_FEDERATED_SEARCH_ONLY_LOGGEDIN', FILTER_VALIDATE_BOOLEAN) ?? $this->kbinFederatedSearchOnlyLoggedin,
            $this->find($results, 'MBIN_SIDEBAR_SECTIONS_RANDOM_LOCAL_ONLY', FILTER_VALIDATE_BOOLEAN) ?? $this->mbinSidebarSectionsRandomLocalOnly,
            $this->find($results, 'MBIN_SIDEBAR_SECTIONS_USERS_LOCAL_ONLY', FILTER_VALIDATE_BOOLEAN) ?? $this->mbinSidebarSectionsUsersLocalOnly,
            $this->find($results, 'MBIN_SSO_REGISTRATIONS_ENABLED', FILTER_VALIDATE_BOOLEAN) ?? $this->mbinSsoRegistrationsEnabled,
            $this->find($results, 'MBIN_RESTRICT_MAGAZINE_CREATION', FILTER_VALIDATE_BOOLEAN) ?? $this->mbinRestrictMagazineCreation,
            $this->find($results, 'MBIN_SSO_SHOW_FIRST', FILTER_VALIDATE_BOOLEAN) ?? $this->mbinSsoShowFirst,
            $this->find($results, 'MBIN_DOWNVOTES_MODE') ?? $this->mbinDownvotesMode->value,
            $newUsersNeedApprovalEdited,
            $this->find($results, 'MBIN_USE_FEDERATION_ALLOW_LIST', FILTER_VALIDATE_BOOLEAN) ?? $this->mbinUseFederationAllowList,
            $this->find($results, 'MBIN_AUTHORIZED_FETCH', FILTER_VALIDATE_BOOLEAN) ?? $this->mbinAuthorizedFetch,
            $this->find($results, 'MBIN_SSO_AVATAR_OVERRIDE', FILTER_VALIDATE_BOOLEAN) ?? $this->mbinSsoAvatarOverride,
        );
    }

    /**
     * @return string[]
     */
    private static function parseLockedSettings(string $value): array
    {
        $names = array_values(array_unique(array_filter(
            array_map('trim', explode(',', $value)),
            fn (string $name) => '' !== $name,
        )));

        foreach ($names as $name) {
            if (!property_exists(SettingsDto::class, $name)) {
                throw new \InvalidArgumentException(\sprintf('MBIN_LOCKED_SETTINGS names "%s", which is not a setting.', $name));
            }

            if (!\in_array($name, self::LOCKABLE_SETTINGS, true)) {
                throw new \InvalidArgumentException(\sprintf('MBIN_LOCKED_SETTINGS names "%s", which has no environment variable to fall back on, so it cannot be locked.', $name));
            }
        }

        return $names;
    }

    /**
     * Whether the setting is named in MBIN_LOCKED_SETTINGS, so that only the
     * environment sets it and an admin cannot change it.
     */
    public function isLocked(string $name): bool
    {
        return \in_array($name, $this->lockedSettings, true);
    }

    /**
     * @return string[]
     */
    public function getLockedSettings(): array
    {
        return $this->lockedSettings;
    }

    private function find(array $results, string $name, ?int $filter = null)
    {
        $res = array_values(array_filter($results, fn ($s) => $s->name === $name));

        if (\count($res)) {
            $res = $res[0]->value ?? $res[0]->json;

            if ($filter) {
                $res = filter_var($res, $filter);
            }

            return $res;
        }

        return null;
    }

    public function getDto(): SettingsDto
    {
        return $this->instanceDto ??= $this->load();
    }

    public function save(SettingsDto $dto): void
    {
        $rows = $this->repository->findAllIndexedByName();

        foreach ($dto as $name => $value) {
            // Never write a locked setting. A row left from before it was locked
            // stays in place, ignored, and applies again if it is unlocked.
            if ($this->isLocked($name)) {
                continue;
            }

            $s = $rows[$name] ?? null;

            if (\is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            if (!\is_string($value) && !\is_array($value)) {
                $value = \strval($value);
            }

            if (!$s) {
                $s = new Settings($name, $value);
            }

            if (\is_array($value)) {
                $s->json = $value;
            } else {
                $s->value = $value;
            }

            $this->entityManager->persist($s);
        }

        $this->entityManager->flush();
    }

    #[Pure]
    public function isLocalUrl(string $url): bool
    {
        return parse_url($url, PHP_URL_HOST) === $this->get('KBIN_DOMAIN');
    }

    /**
     * Check if an instance is banned by
     * checking if the instance URL has a match with the banned instances list.
     *
     * @param string $inboxUrl the inbox URL to check
     */
    public function isBannedInstance(string $inboxUrl): bool
    {
        $host = parse_url($inboxUrl, PHP_URL_HOST);
        if (null === $host) {
            // Try to retrieve the caller function (commented-out for performance reasons)
            // $bt = debug_backtrace();
            // $caller_function = ($bt[1]) ? $bt[1]['function'] : 'Unknown function caller';
            $this->logger->error('SettingsManager::isBannedInstance: unable to parse host from URL: {url}', ['url' => $inboxUrl]);

            // Do not retry, retrying will always cause a failure
            throw new UnrecoverableMessageHandlingException(\sprintf('Invalid URL provided: %s', $inboxUrl));
        }

        $finalUrl = str_replace('www.', '', $host);
        if (!$this->getUseAllowList()) {
            return \in_array($finalUrl, $this->instanceRepository->getBannedInstanceUrls());
        } else {
            // when using an allow list the instance is considered banned if it does not exist or if it is not explicitly allowed
            $instance = $this->instanceRepository->findOneBy(['domain' => $finalUrl]);

            return null === $instance || !$instance->isExplicitlyAllowed;
        }
    }

    /** @return Instance[] */
    public function getBannedInstances(): array
    {
        return $this->instanceRepository->getBannedInstances();
    }

    public function getUseAllowList(): bool
    {
        return $this->getDto()->MBIN_USE_FEDERATION_ALLOW_LIST;
    }

    /**
     * Whether inbound ActivityPub GET requests must carry a valid HTTP signature.
     *
     * Off by default: turning it on refuses to serve ActivityPub to peers that
     * do not sign their GET requests.
     */
    public function isAuthorizedFetchEnabled(): bool
    {
        return $this->getDto()->MBIN_AUTHORIZED_FETCH;
    }

    /**
     * Whether a member whose account is linked to the OIDC provider may replace
     * the profile picture it supplies with an upload of their own.
     *
     * On by default. Turned off, the provider's picture is the only avatar such
     * a member can have, and it is applied again at every OIDC login.
     */
    public function isSsoAvatarOverrideAllowed(): bool
    {
        return $this->getDto()->MBIN_SSO_AVATAR_OVERRIDE;
    }

    public function getAllowedInstances(): array
    {
        return $this->instanceRepository->getAllowedInstances($this->getUseAllowList());
    }

    public function get(string $name)
    {
        return $this->getDto()->{$name};
    }

    public function getDownvotesMode(): DownvotesMode
    {
        return DownvotesMode::from($this->getDto()->MBIN_DOWNVOTES_MODE);
    }

    public function getNewUsersNeedApproval(): bool
    {
        return $this->getDto()->MBIN_NEW_USERS_NEED_APPROVAL;
    }

    public function set(string $name, $value): void
    {
        if ($this->isLocked($name)) {
            throw new \LogicException(\sprintf('%s is locked by MBIN_LOCKED_SETTINGS, so only the environment can change it.', $name));
        }

        $dto = $this->getDto();
        $dto->{$name} = $value;

        $this->save($dto);
    }

    public function getValue(string $name): string
    {
        return $this->getDto()->{$name};
    }

    public function getLocale(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        return $request->cookies->get('mbin_lang') ?? $request->getLocale() ?? $this->get('KBIN_DEFAULT_LANG');
    }

    public function getMaxImageBytes(): int
    {
        return $this->mbinMaxImageBytes;
    }

    public function getMaxImageByteString(): string
    {
        $bytes = $this->mbinMaxImageBytes;
        // We use 1000 for MB (instead of 1024, which would be MiB)
        // Linux is using SI standard, see also: https://wiki.ubuntu.com/UnitsPolicy
        $megaBytes = round($bytes / pow(1000, 2), 2);

        return $megaBytes.' MB';
    }

    public function getSearchLang(): string
    {
        return $this->mbinSearchLang;
    }
}
