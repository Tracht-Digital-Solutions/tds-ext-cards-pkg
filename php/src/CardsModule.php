<?php
declare(strict_types=1);

namespace Tds\Ext\Cards;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\App;
use Tds\Ext\Cards\Domain\CardRepository;
use Tds\Ext\Cards\Support\CardBlocks;
use Tds\Ext\Cards\Support\CardDomain;
use Tds\Ext\Cards\Support\CardImage;
use Tds\Frontend\Contract\AbstractModule;
use Tds\Frontend\Contract\ApiDocSource;
use Tds\Frontend\Contract\CacheEvent;
use Tds\Frontend\Contract\ConnectedSiteCache;
use Tds\Frontend\Contract\PermissionDef;
use Tds\Frontend\Contract\ReportingSiteCache;
use Tds\Frontend\Contract\SettingsStore;
use Tds\Frontend\Contract\SiteCache;
use Tds\Frontend\Contract\SiteConnectionException;
use Tds\Frontend\Contract\SiteConnections;
use Tds\Frontend\Contract\SiteKeyProtected;
use Tds\Frontend\Contract\UserContext;
use Tds\Frontend\Contract\ModuleHttp;

/**
 * Business-card pages: one linktree-style page per customer, created in the
 * panel, served by `tds-card-frontend` on the customer's own domain.
 *
 * ### One site, many hosts — why there is no site registry here
 *
 * The website-CMS has a `cms_site` table because several deployed sites each own
 * their content. Cards are the opposite shape: ONE deployed app answers every
 * customer domain and picks the card by `Host`. So there is exactly one site
 * connection (`cards`/`default`), one site key and one cache origin, and the
 * per-customer axis is a row in `card_page`, not a site.
 *
 * That also means the customer domains are NOT origins of this API. Nothing in a
 * browser on a card page calls it — the whole card is server-rendered — so there
 * is no CORS entry and no second pairing per domain.
 *
 * ### The public surface is unauthenticated and fails soft
 *
 * Every public handler catches and answers an empty shape. The card app's
 * content fetch has a committed fallback for nothing at all, so a `500` here
 * would be a blank page on a customer's domain; an empty answer is a `404`,
 * which is never cached and therefore self-healing.
 */
final class CardsModule extends AbstractModule implements ApiDocSource, SiteKeyProtected
{
    use ModuleHttp;

    /** The single site connection this module owns. */
    public const RESOURCE_TYPE = 'cards';
    public const RESOURCE_ID = 'default';

    /** The setting namespace, shared by the panel's settings island. */
    private const NS = 'cards';

    private const LANGS = ['de', 'en'];

    private const SURFACES = ['paper', 'ink', 'navy', 'sand'];
    private const THEMES = ['light', 'dark'];

    public function id(): string
    {
        return 'cards';
    }

    /** @return PermissionDef[] */
    public function permissions(): array
    {
        return [
            new PermissionDef('cards:read', 'Visitenkarten ansehen', 'cards'),
            new PermissionDef('cards:write', 'Visitenkarten bearbeiten', 'cards'),
        ];
    }

    /** @return string[] */
    public function migrations(): array
    {
        return [__DIR__ . '/../db/migrations'];
    }

    public function register(App $app): void
    {
        $c = $app->getContainer();
        // Bound UNCONDITIONALLY. Never `if (!$c->has(CardRepository::class))`:
        // PHP-DI answers `has()` from autowiring too, so for any concrete class
        // the answer is always true and the guard skips the binding — after
        // which the container autowires something that looks identical until a
        // constructor argument it cannot guess appears. That cost the
        // website-CMS every one of its write routes for months.
        $c?->set(CardRepository::class, static fn ($c) => new CardRepository($c->get(PDO::class)));

        if ($c === null) {
            return;
        }

        $this->registerPublic($app, $c);
        $this->registerAdmin($app, $c);
    }

    /* ==================================================================== */
    /* public                                                                */
    /* ==================================================================== */

    private function registerPublic(App $app, ContainerInterface $c): void
    {
        // One card, by the host it is served on or by its fallback slug.
        $app->get('/content/card', function (Request $req, Response $res) use ($c): Response {
            try {
                $query = $req->getQueryParams();
                $repo = $c->get(CardRepository::class);
                $host = isset($query['host']) ? (string) $query['host'] : '';
                $slug = isset($query['slug']) ? (string) $query['slug'] : '';

                $card = null;
                if ($host !== '') {
                    $card = $repo->publicByDomain($host);
                } elseif ($slug !== '') {
                    $normalised = CardDomain::slug($slug);
                    $card = $normalised === null ? null : $repo->publicBySlug($normalised);
                }

                return $card === null
                    ? self::json($res, ['card' => null], 404)
                    : self::json($res, ['card' => $card]);
            } catch (\Throwable $e) {
                error_log('[cards] public card read failed: ' . $e->getMessage());
                return self::json($res, ['card' => null], 404);
            }
        });

        // The index: addresses only. The card app resolves its sitemap, its
        // cache events and its rebuild list from this, several times per
        // rebuild, which is why it does not ship whole cards.
        $app->get('/content/cards', function (Request $req, Response $res) use ($c): Response {
            try {
                return self::json($res, ['cards' => $c->get(CardRepository::class)->publicIndex()]);
            } catch (\Throwable $e) {
                error_log('[cards] public index failed: ' . $e->getMessage());
                return self::json($res, ['cards' => []]);
            }
        });

        // A card's image. Deliberately unauthenticated: it is loaded by an
        // `<img>` on another origin, which sends no credentials anyway.
        $app->get('/content/card/{slug:[a-z0-9-]+}/{kind:portrait|logo}', function (Request $req, Response $res, array $args) use ($c): Response {
            try {
                $repo = $c->get(CardRepository::class);
                $card = $repo->publicBySlug((string) $args['slug']);
                if ($card === null) {
                    return self::json($res, ['error' => 'not_found'], 404);
                }
                return self::image($req, $res, $repo, (int) $card['id'], (string) $args['kind']);
            } catch (\Throwable $e) {
                error_log('[cards] public image failed: ' . $e->getMessage());
                return self::json($res, ['error' => 'not_found'], 404);
            }
        });
    }

    /* ==================================================================== */
    /* admin                                                                 */
    /* ==================================================================== */

    private function registerAdmin(App $app, ContainerInterface $c): void
    {
        // Static paths before the `{slug}` ones. FastRoute prefers a static
        // match, so this is for the reader rather than the router — but a reader
        // who thinks `/cards/summary` is a card called "summary" writes the next
        // route wrongly.
        $app->get('/cards/summary', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'cards:read', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(CardRepository::class);
            return self::json($res, [
                'total' => $repo->count(),
                'published' => $repo->countPublished(),
            ]);
        });

        $app->get('/cards/connection', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'cards:read', $res)) !== null) {
                return $deny;
            }
            $connections = self::connections($c);
            if ($connections === null) {
                return self::json($res, ['error' => 'Site connection service is not available'], 503);
            }
            $connection = $connections->get(self::RESOURCE_TYPE, self::RESOURCE_ID);
            return $connection === null
                ? self::json($res, ['error' => 'Connection not found'], 404)
                : self::json($res, ['connection' => $connection->toArray()]);
        });

        $app->delete('/cards/connection', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'cards:write', $res)) !== null) {
                return $deny;
            }
            $connections = self::connections($c);
            if ($connections === null) {
                return self::json($res, ['error' => 'Site connection service is not available'], 503);
            }
            return self::json($res, [
                'ok' => true,
                'deleted' => $connections->delete(self::RESOURCE_TYPE, self::RESOURCE_ID),
            ]);
        });

        $app->post('/cards/connection/pairing', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'cards:write', $res)) !== null) {
                return $deny;
            }
            $connections = self::connections($c);
            if ($connections === null) {
                return self::json($res, ['error' => 'Site connection service is not available'], 503);
            }
            $body = (array) $req->getParsedBody();
            $origin = trim((string) ($body['origin'] ?? ''));
            try {
                $pairing = $connections->createPairing(
                    self::RESOURCE_TYPE,
                    self::RESOURCE_ID,
                    $origin,
                    'cards',
                    [self::RESOURCE_TYPE => self::RESOURCE_ID],
                    // Only what the card app's SERVER reads. The image route
                    // sits under `/content/card`, so it is covered.
                    ['/content/card', '/content/cards'],
                );
                return self::json($res, $connections->deliverPairing($pairing, self::apiBase($req))->toArray(), 201);
            } catch (SiteConnectionException $e) {
                return self::json($res, ['error' => $e->getMessage(), 'code' => $e->errorCode], $e->httpStatus);
            } catch (\Throwable $e) {
                error_log('[cards] pairing failed: ' . $e->getMessage());
                return self::json($res, ['error' => 'Pairing could not be created'], 503);
            }
        });

        $app->get('/cards', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'cards:read', $res)) !== null) {
                return $deny;
            }
            return self::json($res, ['cards' => $c->get(CardRepository::class)->all()]);
        });

        $app->post('/cards', function (Request $req, Response $res) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'cards:write', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(CardRepository::class);
            $body = (array) $req->getParsedBody();

            // The slug may be proposed from the name, which is what the shop's
            // order hand-off does — it has a company, never a URL.
            $slug = CardDomain::slug(is_string($body['slug'] ?? null) ? $body['slug'] : '');
            if ($slug === null) {
                $slug = CardDomain::slugify((string) ($body['displayName'] ?? $body['companyName'] ?? ''));
            }
            if ($slug === null) {
                return self::json($res, ['error' => 'Die Adresse fehlt oder ist nicht erlaubt.'], 422);
            }
            if ($repo->find($slug) !== null) {
                return self::json($res, ['error' => 'Diese Adresse ist schon vergeben.'], 409);
            }

            $fields = self::fields($repo, $body, $slug, null);
            if (is_string($fields)) {
                return self::json($res, ['error' => $fields], 422);
            }
            $repo->put($slug, $fields);
            $card = $repo->find($slug);
            $cache = self::fireCache($c, [new CacheEvent('card', $slug)]);
            return self::json($res, array_merge(['card' => $card], $cache), 201);
        });

        $app->get('/cards/{slug:[a-z0-9-]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'cards:read', $res)) !== null) {
                return $deny;
            }
            $card = $c->get(CardRepository::class)->find((string) $args['slug']);
            return $card === null
                ? self::json($res, ['error' => 'Card not found'], 404)
                : self::json($res, ['card' => $card]);
        });

        $app->put('/cards/{slug:[a-z0-9-]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'cards:write', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(CardRepository::class);
            $slug = (string) $args['slug'];
            $existing = $repo->find($slug);
            if ($existing === null) {
                return self::json($res, ['error' => 'Card not found'], 404);
            }
            $fields = self::fields($repo, (array) $req->getParsedBody(), $slug, $existing);
            if (is_string($fields)) {
                return self::json($res, ['error' => $fields], 422);
            }
            $repo->put($slug, $fields);
            $card = $repo->find($slug);

            // Both addresses may have changed in one save: a card that moved to
            // its own domain must drop off the old one too, so the event carries
            // the slug and the frontend expands it into every path it owns.
            $cache = self::fireCache($c, [new CacheEvent('card', $slug)]);
            return self::json($res, array_merge(['card' => $card], $cache));
        });

        $app->delete('/cards/{slug:[a-z0-9-]+}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'cards:write', $res)) !== null) {
                return $deny;
            }
            $slug = (string) $args['slug'];
            $deleted = $c->get(CardRepository::class)->delete($slug);
            if (!$deleted) {
                return self::json($res, ['error' => 'Card not found'], 404);
            }
            $cache = self::fireCache($c, [new CacheEvent('card', $slug)]);
            return self::json($res, array_merge(['ok' => true], $cache));
        });

        // The admin preview: unlike the public route this serves a draft's image
        // too, which is the point of a preview.
        $app->get('/cards/{slug:[a-z0-9-]+}/image/{kind:portrait|logo}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'cards:read', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(CardRepository::class);
            $card = $repo->find((string) $args['slug']);
            if ($card === null) {
                return self::json($res, ['error' => 'Card not found'], 404);
            }
            return self::image($req, $res, $repo, (int) $card['id'], (string) $args['kind']);
        });

        $app->post('/cards/{slug:[a-z0-9-]+}/image/{kind:portrait|logo}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'cards:write', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(CardRepository::class);
            $slug = (string) $args['slug'];
            $card = $repo->find($slug);
            if ($card === null) {
                return self::json($res, ['error' => 'Card not found'], 404);
            }

            $file = $req->getUploadedFiles()['file'] ?? null;
            if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
                return self::json($res, ['error' => 'Es wurde keine Datei übertragen.'], 400);
            }
            // Size BEFORE the stream: reading first means a refusal that already
            // pulled the bytes through memory.
            if ((int) $file->getSize() > CardImage::MAX_BYTES) {
                return self::json($res, ['error' => 'Das Bild ist zu groß (maximal 2 MB).'], 413);
            }
            $bytes = (string) $file->getStream();
            // The declared type is whatever the uploader said; the bytes are the
            // only claim worth believing.
            $sniffed = CardImage::sniff($bytes);
            if ($sniffed === null) {
                return self::json($res, ['error' => 'Nur PNG, JPEG oder WebP.'], 415);
            }
            $repo->putAsset((int) $card['id'], (string) $args['kind'], $sniffed['mime'], $bytes, $sniffed['width'], $sniffed['height']);
            $cache = self::fireCache($c, [new CacheEvent('card', $slug)]);
            return self::json($res, array_merge(['ok' => true, 'image' => $sniffed], $cache), 201);
        });

        $app->delete('/cards/{slug:[a-z0-9-]+}/image/{kind:portrait|logo}', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'cards:write', $res)) !== null) {
                return $deny;
            }
            $repo = $c->get(CardRepository::class);
            $slug = (string) $args['slug'];
            $card = $repo->find($slug);
            if ($card === null) {
                return self::json($res, ['error' => 'Card not found'], 404);
            }
            $deleted = $repo->deleteAsset((int) $card['id'], (string) $args['kind']);
            $cache = self::fireCache($c, [new CacheEvent('card', $slug)]);
            return self::json($res, array_merge(['ok' => true, 'deleted' => $deleted], $cache));
        });

        $app->post('/cards/{slug:[a-z0-9-]+}/cache/rebuild', function (Request $req, Response $res, array $args) use ($c): Response {
            if (($deny = self::require($c->get(UserContext::class), 'cards:write', $res)) !== null) {
                return $deny;
            }
            $slug = (string) $args['slug'];
            if ($c->get(CardRepository::class)->find($slug) === null) {
                return self::json($res, ['error' => 'Card not found'], 404);
            }
            $report = self::fireCache($c, [new CacheEvent('card', $slug)]);
            return self::json($res, $report, self::manualCacheStatus($report));
        });
    }

    /* ==================================================================== */
    /* validation                                                            */
    /* ==================================================================== */

    /**
     * Turn a request body into the columns to write, or a German error message.
     *
     * A partial body UPDATES, it does not blank: an absent key keeps what the
     * row already has. The panel sends whole cards, but the shop's order
     * hand-off sends four fields, and a replace-everything write there would
     * erase an operator's work the moment a customer reordered.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>|string
     */
    private static function fields(CardRepository $repo, array $body, string $slug, ?array $existing): array|string
    {
        // --- the address ------------------------------------------------
        $domain = null;
        if (array_key_exists('domain', $body)) {
            $raw = is_string($body['domain']) ? trim($body['domain']) : '';
            if ($raw !== '') {
                $domain = CardDomain::normalize($raw);
                if ($domain === null) {
                    return 'Die Domain ist keine Adresse, die ausgeliefert werden kann.';
                }
                if ($repo->domainTaken($domain, $slug)) {
                    return 'Diese Domain gehört schon zu einer anderen Karte.';
                }
            }
        } elseif ($existing !== null) {
            $domain = $existing['domain'] ?? null;
        }

        // --- publication state ------------------------------------------
        $draft = array_key_exists('draft', $body)
            ? (bool) $body['draft']
            : (bool) ($existing['draft'] ?? true);

        // A published card needs a timestamp, and it keeps the one it had:
        // re-saving a live card must not move it to the top of a feed.
        $publishedAt = null;
        if (!$draft) {
            $previous = $existing['publishedAt'] ?? null;
            $publishedAt = is_string($previous) && $previous !== '' ? $previous : $repo->now();
        }

        $text = static function (string $key, int $max, ?string $fallback) use ($body): ?string {
            if (!array_key_exists($key, $body)) {
                return $fallback;
            }
            $value = is_string($body[$key]) ? trim($body[$key]) : '';
            return $value === '' ? null : mb_substr($value, 0, $max);
        };

        $displayName = $text('displayName', 160, is_string($existing['displayName'] ?? null) ? $existing['displayName'] : null);
        if ($displayName === null || $displayName === '') {
            return 'Ein Name muss auf der Karte stehen.';
        }

        $accent = self::accent($body['accent'] ?? null, (string) ($existing['accent'] ?? '#1f3a5f'));
        if ($accent === null) {
            return 'Die Farbe muss als Hex-Wert angegeben werden, zum Beispiel #1f3a5f.';
        }

        $email = $text('email', 190, is_string($existing['email'] ?? null) ? $existing['email'] : null);
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Die E-Mail-Adresse ist keine Adresse.';
        }

        $website = $text('website', 300, is_string($existing['website'] ?? null) ? $existing['website'] : null);
        if ($website !== null && !CardBlocks::hrefOk($website)) {
            return 'Die Website muss mit http:// oder https:// beginnen.';
        }

        $blocks = array_key_exists('blocks', $body)
            ? CardBlocks::sanitize($body['blocks'])
            : CardBlocks::sanitize($existing['blocks'] ?? []);

        return [
            'domain' => $domain,
            'company_id' => self::companyId($body, $existing),
            'lang' => self::lang($body['lang'] ?? ($existing['lang'] ?? 'de')),
            'display_name' => $displayName,
            'role' => $text('role', 160, is_string($existing['role'] ?? null) ? $existing['role'] : null),
            'company_name' => $text('companyName', 160, is_string($existing['companyName'] ?? null) ? $existing['companyName'] : null),
            'tagline' => $text('tagline', 240, is_string($existing['tagline'] ?? null) ? $existing['tagline'] : null),
            'phone' => $text('phone', 60, is_string($existing['phone'] ?? null) ? $existing['phone'] : null),
            'mobile' => $text('mobile', 60, is_string($existing['mobile'] ?? null) ? $existing['mobile'] : null),
            'email' => $email,
            'website' => $website,
            'address_line' => $text('addressLine', 190, is_string($existing['addressLine'] ?? null) ? $existing['addressLine'] : null),
            'postal_code' => $text('postalCode', 20, is_string($existing['postalCode'] ?? null) ? $existing['postalCode'] : null),
            'city' => $text('city', 120, is_string($existing['city'] ?? null) ? $existing['city'] : null),
            'country' => self::country($body, $existing),
            'accent' => $accent,
            'surface' => self::oneOf($body['surface'] ?? null, self::SURFACES, (string) ($existing['surface'] ?? 'paper')),
            'theme' => self::oneOf($body['theme'] ?? null, self::THEMES, (string) ($existing['theme'] ?? 'light')),
            'meta_description' => $text('metaDescription', 200, is_string($existing['metaDescription'] ?? null) ? $existing['metaDescription'] : null),
            'blocks' => CardBlocks::encode($blocks),
            'draft' => $draft ? 1 : 0,
            'published_at' => $publishedAt,
        ];
    }

    /** @param array<string, mixed> $body @param array<string, mixed>|null $existing */
    private static function companyId(array $body, ?array $existing): ?int
    {
        if (array_key_exists('companyId', $body)) {
            $value = $body['companyId'];
            if ($value === null || $value === '') {
                return null;
            }
            $id = (int) $value;
            return $id > 0 ? $id : null;
        }
        $previous = $existing['companyId'] ?? null;
        return is_int($previous) && $previous > 0 ? $previous : null;
    }

    /** @param array<string, mixed> $body @param array<string, mixed>|null $existing */
    private static function country(array $body, ?array $existing): ?string
    {
        if (!array_key_exists('country', $body)) {
            $previous = $existing['country'] ?? null;
            return is_string($previous) && $previous !== '' ? $previous : null;
        }
        $value = strtoupper(trim(is_string($body['country']) ? $body['country'] : ''));
        return preg_match('/^[A-Z]{2}$/', $value) === 1 ? $value : null;
    }

    /** A hex colour, or null when the value was present and unusable. */
    private static function accent(mixed $value, string $fallback): ?string
    {
        if ($value === null) {
            return $fallback;
        }
        $hex = strtolower(trim((string) $value));
        if ($hex === '') {
            return $fallback;
        }
        return preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/', $hex) === 1 ? $hex : null;
    }

    /** @param list<string> $allowed */
    private static function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        $v = is_string($value) ? strtolower(trim($value)) : '';
        return in_array($v, $allowed, true) ? $v : $fallback;
    }

    private static function lang(mixed $value): string
    {
        $v = is_string($value) ? strtolower($value) : '';
        return in_array($v, self::LANGS, true) ? $v : 'de';
    }

    /* ==================================================================== */
    /* helpers                                                               */
    /* ==================================================================== */

    /**
     * Serve a stored image, answering a conditional request without the blob.
     *
     * `nosniff` because these bytes are user-supplied and served from the API
     * origin, where the session cookie lives: a browser that guesses HTML out of
     * an image would be executing it next to that cookie.
     */
    private static function image(Request $req, Response $res, CardRepository $repo, int $cardId, string $kind): Response
    {
        $meta = $repo->assetMeta($cardId, $kind);
        if ($meta === null) {
            return self::json($res, ['error' => 'not_found'], 404);
        }
        $etag = CardImage::etag($cardId, $kind, (string) $meta['updated_at']);
        if ($req->getHeaderLine('If-None-Match') === $etag) {
            return $res->withStatus(304)->withHeader('ETag', $etag);
        }
        $asset = $repo->assetBytes($cardId, $kind);
        if ($asset === null) {
            return self::json($res, ['error' => 'not_found'], 404);
        }
        $bytes = (string) $asset['content'];
        $res->getBody()->write($bytes);
        return $res
            ->withHeader('Content-Type', (string) $asset['mime_type'])
            ->withHeader('Content-Length', (string) strlen($bytes))
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', 'public, max-age=300')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Disposition', 'inline');
    }

    /**
     * Ask the card app to re-render what a change touched.
     *
     * Semantic events, never paths: which URLs a card owns is the frontend's
     * knowledge — and with one app serving many domains it is knowledge this
     * module could not reproduce without also holding the host mapping twice.
     *
     * The report says whether a request actually went out. Never turn `ok` into
     * "neu gebaut" in the panel; `not_configured` is the normal state before
     * pairing and must read as such.
     *
     * @param CacheEvent[] $events
     * @return array{cache_status:string,cached:bool,rebuilt:array,skipped:array,failed:array,unknownEvents:array}
     */
    private static function fireCache(ContainerInterface $c, array $events): array
    {
        try {
            if ($events === []) {
                return self::emptyCacheReport('skipped');
            }
            $connection = self::connection($c);
            if ($connection !== null && $c->has(ConnectedSiteCache::class)) {
                $cache = $c->get(ConnectedSiteCache::class);
                $reports = [];
                foreach ($events as $event) {
                    $reports[] = $cache->refresh(self::RESOURCE_TYPE, self::RESOURCE_ID, $event)->toArray();
                }
                return self::mergeCacheReports($reports);
            }
            if ($connection !== null) {
                return self::emptyCacheReport('not_configured');
            }

            // Unpaired: the settings store may still hold an origin and a token,
            // which is how a local stack refreshes before anything is paired.
            if (!$c->has(SiteCache::class)) {
                return self::emptyCacheReport('not_configured');
            }
            $store = self::setting($c);
            $origin = trim((string) ($store?->get(self::NS, 'cache_url') ?? getenv('CARDS_CACHE_URL') ?: ''));
            $token = (string) ($store?->getSecret(self::NS, 'cache_token') ?? '');
            if ($token === '') {
                $token = (string) (getenv('CARDS_CACHE_TOKEN') ?: '');
            }
            if ($origin === '' || $token === '') {
                return self::emptyCacheReport('not_configured');
            }
            $cache = $c->get(SiteCache::class);
            // Ask before sending: `rebuild()` is a documented no-op without a
            // token, and a no-op reported as a rebuild is the same lie.
            if (!$cache->isConfigured($origin, $token)) {
                return self::emptyCacheReport('not_configured');
            }
            if ($cache instanceof ReportingSiteCache) {
                return $cache->rebuildWithResult($origin, $token, $events)->toArray();
            }
            $cache->rebuild($origin, $token, $events);
            $report = self::emptyCacheReport('skipped');
            $report['unknownEvents'][] = ['reason' => 'legacy_transport_has_no_result'];
            return $report;
        } catch (\Throwable $e) {
            // Best-effort: a refresh failure must never turn an already-saved
            // card into a 500. Never log the token.
            error_log('[cards] page-cache request failed: ' . $e->getMessage());
            $report = self::emptyCacheReport('failed');
            $report['failed'][] = ['reason' => 'transport_error'];
            return $report;
        }
    }

    private static function connections(ContainerInterface $c): ?SiteConnections
    {
        try {
            return $c->has(SiteConnections::class) ? $c->get(SiteConnections::class) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function connection(ContainerInterface $c): mixed
    {
        try {
            return self::connections($c)?->get(self::RESOURCE_TYPE, self::RESOURCE_ID);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function setting(ContainerInterface $c): ?SettingsStore
    {
        return $c->has(SettingsStore::class) ? $c->get(SettingsStore::class) : null;
    }

    private static function apiBase(Request $req): string
    {
        $uri = $req->getUri();
        return $uri->getScheme() . '://' . $uri->getAuthority();
    }

    /** @return array{cache_status:string,cached:bool,rebuilt:array,skipped:array,failed:array,unknownEvents:array} */
    private static function emptyCacheReport(string $status): array
    {
        return [
            'cache_status' => $status,
            'cached' => false,
            'rebuilt' => [],
            'skipped' => [],
            'failed' => [],
            'unknownEvents' => [],
        ];
    }

    /** @param list<array<string,mixed>> $reports */
    private static function mergeCacheReports(array $reports): array
    {
        if ($reports === []) {
            return self::emptyCacheReport('skipped');
        }
        $merged = self::emptyCacheReport('refreshed');
        $merged['cached'] = true;
        $statuses = [];
        foreach ($reports as $report) {
            $statuses[] = (string) ($report['cache_status'] ?? 'failed');
            $merged['cached'] = $merged['cached'] && (bool) ($report['cached'] ?? false);
            foreach (['rebuilt', 'skipped', 'failed', 'unknownEvents'] as $key) {
                $values = $report[$key] ?? [];
                if (is_array($values)) {
                    $merged[$key] = array_merge($merged[$key], $values);
                }
            }
        }
        if (in_array('failed', $statuses, true)) {
            $merged['cache_status'] = 'failed';
        } elseif (in_array('not_configured', $statuses, true)) {
            $merged['cache_status'] = count(array_unique($statuses)) === 1 ? 'not_configured' : 'failed';
        } elseif (!$merged['cached'] || in_array('skipped', $statuses, true)) {
            $merged['cache_status'] = 'skipped';
        }
        return $merged;
    }

    /** @param array{cache_status:string,cached:bool} $report */
    private static function manualCacheStatus(array $report): int
    {
        return match ($report['cache_status']) {
            'refreshed' => 202,
            'not_configured' => 503,
            default => 502,
        };
    }

    /** @return list<array<string, mixed>> */
    public function apiDocs(): array
    {
        return require __DIR__ . '/../docs/api.php';
    }

    /**
     * The routes the card app's SERVER reads.
     *
     * **Both prefixes are needed, and it looks as if one would do.** The
     * middleware matches on SEGMENT boundaries (`$path === $prefix ||
     * str_starts_with($path . '/', $prefix . '/')`), deliberately, so that
     * `/content/blogroll` is not covered by `/content/blog`. Under that rule
     * `/content/card` covers the singular route and the image route beneath it —
     * and does NOT cover `/content/cards`, which would have been served
     * unprotected while looking exactly like a route somebody chose to leave
     * open.
     *
     * Never widen this to `/content`: that would also gate blog-cms's and the
     * website-CMS's routes, i.e. one module deciding another's surface, and it
     * would stop doing so the day either renamed a path, with nothing to notice.
     *
     * @return list<string>
     */
    public function siteKeyRoutes(): array
    {
        return ['/content/card', '/content/cards'];
    }
}
