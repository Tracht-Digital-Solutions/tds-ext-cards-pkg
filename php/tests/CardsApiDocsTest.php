<?php
declare(strict_types=1);

namespace Tds\Ext\Cards\Tests;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Slim\Factory\AppFactory;
use Tds\Ext\Cards\CardsModule;
use Tds\Frontend\Contract\ModuleRegistry;

/**
 * The route documentation this module contributes to the admin frontend's API
 * reference (`GET /wiki.json`), and the site-key prefixes it declares.
 *
 * Prose that sits next to code rots, and a reference full of confident, wrong
 * detail is worse than the bare route list it replaced. So the documented set
 * and the registered set are asserted to be the SAME set, in both directions.
 */
final class CardsApiDocsTest extends TestCase
{
    /** @return string[] "<METHOD> <pattern>" for every route the module mounts */
    private static function mountedRoutes(): array
    {
        $app = AppFactory::createFromContainer(new Container());
        $registry = new ModuleRegistry([new CardsModule()]);
        $registry->registerAll($app);
        return array_keys($registry->routeOwners());
    }

    /** @return string[] */
    private static function documentedRoutes(): array
    {
        return array_map(
            static fn (array $doc): string => strtoupper((string) $doc['method']) . ' ' . $doc['pattern'],
            (new CardsModule())->apiDocs(),
        );
    }

    public function testDocumentsExactlyTheRoutesItMounts(): void
    {
        $mounted = self::mountedRoutes();
        $documented = self::documentedRoutes();
        sort($mounted);
        sort($documented);

        self::assertSame($mounted, $documented);
    }

    public function testEveryEntryIsWellFormed(): void
    {
        $permissions = array_map(static fn ($p): string => $p->id, (new CardsModule())->permissions());

        foreach ((new CardsModule())->apiDocs() as $doc) {
            $where = $doc['method'] . ' ' . $doc['pattern'];

            self::assertNotSame('', trim((string) $doc['summary']), "Leere Zusammenfassung: {$where}");
            // Parenthesised deliberately: `??` binds tighter than `?:`, so the
            // unbracketed form collapses to a constant and asserts nothing.
            $auth = $doc['auth'] ?? (isset($doc['permission']) ? 'permission' : 'public');
            self::assertContains(
                $auth,
                ['public', 'session', 'permission', 'admin', 'token'],
                "Unbekannter auth-Wert: {$where}",
            );
            if (isset($doc['permission'])) {
                // A reference that names a permission nobody can grant is a
                // wrong answer to "why do I get a 403 here".
                self::assertContains($doc['permission'], $permissions, "Unbekannte Permission: {$where}");
            }
            foreach ($doc['params'] ?? [] as $param) {
                self::assertContains($param['in'], ['path', 'query', 'body', 'header'], "Unbekanntes in: {$where}");
                self::assertNotSame('', trim((string) $param['name']), "Parameter ohne Namen: {$where}");
            }
            foreach ($doc['responses'] ?? [] as $response) {
                self::assertIsInt($response['status'], "Status ist kein int: {$where}");
                self::assertNotSame('', trim((string) $response['description']), "Antwort ohne Text: {$where}");
            }
        }
    }

    public function testEveryPathPlaceholderIsDocumented(): void
    {
        // Collected and asserted once rather than inside the loop, so a module
        // with no placeholders still performs an assertion (phpunit's
        // failOnRisky) and every gap is reported instead of only the first.
        $missing = [];
        foreach ((new CardsModule())->apiDocs() as $doc) {
            preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)/', (string) $doc['pattern'], $matches);
            $documented = array_column(
                array_filter($doc['params'] ?? [], static fn (array $p): bool => $p['in'] === 'path'),
                'name',
            );
            foreach ($matches[1] as $placeholder) {
                if (!in_array($placeholder, $documented, true)) {
                    $missing[] = "{$doc['method']} {$doc['pattern']} → {$placeholder}";
                }
            }
        }

        self::assertSame([], $missing, 'Undokumentierte Pfadparameter');
    }

    /**
     * Every public route must be covered by a declared site-key prefix.
     *
     * The direction that matters for THIS module. `/content/cards` is not
     * covered by the prefix `/content/card`, because the middleware matches on
     * segment boundaries — so the plural route would have been served
     * unprotected while looking exactly like a route somebody chose to leave
     * open. Nothing else anywhere would have noticed.
     */
    public function testEveryPublicRouteIsCoveredByASiteKeyPrefix(): void
    {
        $prefixes = (new CardsModule())->siteKeyRoutes();
        $unprotected = [];

        foreach (self::mountedRoutes() as $route) {
            $path = substr($route, (int) strpos($route, ' ') + 1);
            if (!str_starts_with($path, '/content/')) {
                continue;
            }
            if (!self::covers($path, $prefixes)) {
                $unprotected[] = $path;
            }
        }

        self::assertSame([], $unprotected, 'Öffentliche Routen ohne Site-Key-Präfix');
    }

    /** And the other direction: a prefix that no longer matches anything. */
    public function testEverySiteKeyPrefixStillCoversAMountedRoute(): void
    {
        $paths = array_map(
            static fn (string $route): string => substr($route, (int) strpos($route, ' ') + 1),
            self::mountedRoutes(),
        );
        $orphans = [];
        foreach ((new CardsModule())->siteKeyRoutes() as $prefix) {
            $covers = false;
            foreach ($paths as $path) {
                if (self::covers($path, [$prefix])) {
                    $covers = true;
                    break;
                }
            }
            if (!$covers) {
                $orphans[] = $prefix;
            }
        }

        self::assertSame([], $orphans, 'Site-Key-Präfixe ohne passende Route');
    }

    /**
     * A site-key prefix must never cover an admin route — that would turn a CI
     * secret into panel access.
     */
    public function testNoSiteKeyPrefixCoversAnAdminRoute(): void
    {
        $prefixes = (new CardsModule())->siteKeyRoutes();
        $covered = [];
        foreach (self::mountedRoutes() as $route) {
            $path = substr($route, (int) strpos($route, ' ') + 1);
            if (str_starts_with($path, '/content/')) {
                continue;
            }
            if (self::covers($path, $prefixes)) {
                $covered[] = $path;
            }
        }

        self::assertSame([], $covered, 'Verwaltungsrouten dürfen nie per Site-Key erreichbar sein');
    }

    /**
     * The middleware's own rule, copied deliberately.
     *
     * `SiteKeyMiddleware::matches` compares on segment boundaries. A plain
     * `str_starts_with` here would make these tests pass for a prefix the
     * middleware does not honour, which is the exact mistake they exist to
     * catch.
     *
     * @param list<string> $prefixes
     */
    private static function covers(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path . '/', $prefix . '/')) {
                return true;
            }
        }
        return false;
    }
}
