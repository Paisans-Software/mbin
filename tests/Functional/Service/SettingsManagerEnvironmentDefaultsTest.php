<?php

declare(strict_types=1);

namespace App\Tests\Functional\Service;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Unset, each environment variable that can now set one of these settings
 * keeps the default the setting had before it could be set there.
 */
class SettingsManagerEnvironmentDefaultsTest extends KernelTestCase
{
    public function testUnsetVariablesKeepTheEarlierDefaults(): void
    {
        $container = self::getContainer();

        foreach ([
            'kbin_mercure_enabled' => false,
            'kbin_federated_search_only_loggedin' => true,
            'mbin_sidebar_sections_random_local_only' => false,
            'mbin_sidebar_sections_users_local_only' => false,
            'mbin_sso_registrations_enabled' => true,
            'mbin_restrict_magazine_creation' => false,
            'mbin_sso_show_first' => false,
        ] as $parameter => $default) {
            $this->assertSame($default, $container->getParameter($parameter), $parameter);
        }
    }
}
