<?php

declare(strict_types=1);

namespace App\Tests\Functional\Factory;

use App\Factory\ActivityPub\NodeInfoFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What a peer sees when it asks who we are.
 *
 * FORK DIVERGENCE — this test exists only on the fork and must not be offered
 * upstream. It asserts that this instance identifies itself honestly:
 *
 *   software.name       stays 'mbin'  — we ARE running Mbin, patched. Peers record
 *                                       this and some branch on it, so inventing a
 *                                       name makes us unrecognised, not honest.
 *   software.version    carries '+paisans' as SemVer build metadata — ignored for
 *                                       precedence, carried for identification.
 *   software.repository points at the fork — the one field whose job is to say
 *                                       which codebase is running.
 *
 * If a later merge from upstream reverts the repository constant or drops the
 * suffix, these fail, which is the point: the divergence is deliberate and
 * silently losing it would misidentify the instance to every peer.
 */
class ForkIdentityInNodeInfoTest extends KernelTestCase
{
    public function testNodeInfo21IdentifiesTheForkWithoutRenamingTheSoftware(): void
    {
        self::bootKernel();

        $software = self::getContainer()->get(NodeInfoFactory::class)->create('2.1')['software'];

        self::assertSame('mbin', $software['name']);
        self::assertSame('https://github.com/Paisans-Software/mbin', $software['repository']);
        self::assertStringEndsWith('+paisans', $software['version']);
    }

    /**
     * nodeinfo 2.0 has no repository field, so the version suffix is the only
     * signal a 2.0-only peer gets. Asserted separately so that remains true.
     */
    public function testNodeInfo20StillCarriesTheSuffixInTheVersion(): void
    {
        self::bootKernel();

        $software = self::getContainer()->get(NodeInfoFactory::class)->create('2.0')['software'];

        self::assertSame('mbin', $software['name']);
        self::assertArrayNotHasKey('repository', $software);
        self::assertStringEndsWith('+paisans', $software['version']);
    }
}
