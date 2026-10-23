<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use PHPUnit\Framework\TestCase;

/**
 * Every repository method that filters on a magazine's visibility is a listing of
 * magazines or of their content, so it must also apply the access rule. This sweep finds
 * each such method under src/Repository and asserts that it references
 * MagazineAccessClause (apply, dql, sql, applyDiscovery or sqlDiscovery). A new query
 * that copies the upstream `m.visibility = :visible` filter without the access rule fails
 * here instead of leaking a restricted magazine.
 *
 * FORK DIVERGENCE — do not offer this upstream: private magazines.
 */
class MagazineVisibilitySweepTest extends TestCase
{
    /**
     * Methods that filter on magazine visibility and deliberately do not apply the access
     * rule, each with the reason. Keyed by "ShortClassName::method".
     */
    private const array EXCEPTIONS = [
        'InstanceRepository::getInstanceCounts' => 'counts only, per remote instance, for the admin federation page: no magazine or content is listed',
        'MagazineRepository::findForDeletionPaginated' => 'the admin-only queue of magazines marked for deletion',
    ];

    public function testEveryMagazineVisibilityFilterAppliesTheAccessRule(): void
    {
        $missing = [];
        $found = [];
        foreach ($this->repositoryMethods() as $name => $body) {
            if (!$this->filtersOnMagazineVisibility($body)) {
                continue;
            }
            $found[] = $name;
            if (\array_key_exists($name, self::EXCEPTIONS)) {
                continue;
            }
            if (!str_contains($body, 'MagazineAccessClause::')) {
                $missing[] = $name;
            }
        }

        // canaries: if the patterns stop matching these, the sweep is checking nothing
        foreach (['EntryRepository::getEntryQueryBuilder', 'ContentRepository::getQueryAndParameters', 'SearchRepository::search'] as $known) {
            self::assertContains($known, $found, "the sweep no longer recognises $known, so its patterns are broken");
        }
        self::assertSame([], $missing, 'these methods filter on magazine visibility without MagazineAccessClause');
    }

    public function testEveryExceptionStillExists(): void
    {
        $methods = $this->repositoryMethods();
        foreach (array_keys(self::EXCEPTIONS) as $name) {
            self::assertArrayHasKey($name, $methods, "stale exception $name");
            self::assertTrue($this->filtersOnMagazineVisibility($methods[$name]), "exception $name no longer filters on magazine visibility");
        }
    }

    public function testSweepRecognisesTheShapes(): void
    {
        self::assertTrue($this->filtersOnMagazineVisibility("->andWhere('m.visibility = :visible')"));
        self::assertTrue($this->filtersOnMagazineVisibility("->join('e.magazine', 'mag')->andWhere('mag.visibility = :visibility')"));
        self::assertTrue($this->filtersOnMagazineVisibility('SELECT * FROM entry e JOIN magazine mg ON mg.id = e.magazine_id WHERE mg.visibility = :visible'));
        self::assertTrue($this->filtersOnMagazineVisibility('WHERE m.visibility IN (:visibility)'));
        self::assertFalse($this->filtersOnMagazineVisibility("->andWhere('e.visibility = :visible')->andWhere('u.visibility = :visible')"));
    }

    private function filtersOnMagazineVisibility(string $body): bool
    {
        $aliases = ['m' => true];
        $patterns = [
            // DQL joins: ->join('e.magazine', 'm'), ->leftJoin('x.magazine', 'mag')
            "/[jJ]oin\\(\\s*'\\w+\\.magazine'\\s*,\\s*'(\\w+)'/",
            // DQL by class: Magazine::class.' m', FROM App\Entity\Magazine m
            "/Magazine::class\\s*\\.\\s*'\\s+(\\w+)/",
            '/(?:FROM|JOIN)\\s+App\\\\+Entity\\\\+Magazine\\s+(\\w+)/i',
            // native SQL: FROM magazine m, JOIN magazine m
            '/(?:FROM|JOIN)\\s+"?magazine"?\\s+(?:AS\\s+)?(?!ON\\b|WHERE\\b)(\\w+)/i',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $body, $matches)) {
                foreach ($matches[1] as $alias) {
                    $aliases[$alias] = true;
                }
            }
        }

        $names = implode('|', array_map(fn (string $a) => preg_quote($a, '/'), array_keys($aliases)));

        return 1 === preg_match("/\\b(?:$names)\\.visibility\\b/", $body);
    }

    /**
     * @return array<string, string> method bodies keyed by "ShortClassName::method"
     */
    private function repositoryMethods(): array
    {
        $dir = \dirname(__DIR__, 3).'/src/Repository';
        $files = glob($dir.'/*.php');
        self::assertNotEmpty($files, "no repositories found under $dir");

        $methods = [];
        foreach ($files as $file) {
            $class = basename($file, '.php');
            if ('MagazineAccessClause' === $class) {
                continue;
            }
            foreach ($this->methodsOf((string) file_get_contents($file)) as $method => $body) {
                $methods["$class::$method"] = $body;
            }
        }

        return $methods;
    }

    /**
     * @return array<string, string> method name to its source, signature to closing brace
     */
    private function methodsOf(string $source): array
    {
        $tokens = \PhpToken::tokenize($source);
        $count = \count($tokens);
        $methods = [];
        for ($i = 0; $i < $count; ++$i) {
            if (!$tokens[$i]->is(T_FUNCTION)) {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && $tokens[$j]->isIgnorable()) {
                ++$j;
            }
            if ($j >= $count || !$tokens[$j]->is(T_STRING)) {
                // a closure: it is part of the enclosing method's body
                continue;
            }
            $name = $tokens[$j]->text;

            // find the body's opening brace; an abstract or interface method has none
            $k = $j;
            while ($k < $count && '{' !== $tokens[$k]->text && ';' !== $tokens[$k]->text) {
                ++$k;
            }
            if ($k >= $count || ';' === $tokens[$k]->text) {
                continue;
            }

            $depth = 0;
            $body = '';
            for ($m = $k; $m < $count; ++$m) {
                $text = $tokens[$m]->text;
                $body .= $text;
                // "{$x}" and "${x}" inside strings open a brace that a plain } closes
                if ('{' === $text || $tokens[$m]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    ++$depth;
                } elseif ('}' === $text && 0 === --$depth) {
                    break;
                }
            }
            $methods[$name] = $body;
            $i = $m;
        }

        return $methods;
    }
}
