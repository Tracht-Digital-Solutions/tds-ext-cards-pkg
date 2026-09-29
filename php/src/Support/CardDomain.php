<?php
declare(strict_types=1);

namespace Tds\Ext\Cards\Support;

/**
 * How a card's domain is written down, and how a request's `Host` is read back.
 *
 * These two have to be the SAME function, which is the entire reason this class
 * exists rather than a regex at each call site. The card app looks a card up by
 * the host the browser sent; the panel stores what an operator typed. If the two
 * normalisations differ by a trailing dot, an upper-case letter or a `www.`, the
 * card answers 404 on its own domain — a `404` is never cached, so it keeps
 * answering 404, and nothing anywhere is red.
 *
 * `tds-card-frontend/src/lib/host.ts` is the mirror. Change one, change both.
 *
 * Pure functions, no PDO: the tests run without a database.
 */
final class CardDomain
{
    /** Longest a hostname may be, per DNS. */
    public const MAX_LENGTH = 253;

    /**
     * Normalise a domain or a `Host` header into the stored form, or `null` when
     * it is not a hostname this app can serve.
     *
     * Deliberate decisions:
     *
     * - **`www.` is stripped.** A card is one page; serving it at two hostnames
     *   splits its search presence and doubles the certificate work for no gain.
     *   The hosting redirects `www.` to the bare domain, and this makes sure a
     *   request that arrives anyway still finds its card.
     * - **A port is dropped.** `Host` carries one in development
     *   (`mira-markt.de:4399`) and never in production, so keeping it would make
     *   a card resolvable locally and not on the host, or the reverse.
     * - **A trailing dot is dropped.** `mira-markt.de.` is the same name to DNS
     *   and a different string to everything else.
     * - **A scheme or a path is a refusal, not something to strip.** An operator
     *   who pasted `https://mira-markt.de/impressum` meant something we cannot
     *   guess; silently keeping the host would publish a card at an address they
     *   did not choose.
     */
    public static function normalize(?string $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $host = strtolower(trim($value));
        if ($host === '') {
            return null;
        }
        // Anything structural means the input was a URL, not a hostname.
        if (str_contains($host, '/') || str_contains($host, '\\') || str_contains($host, '@')
            || str_contains($host, '?') || str_contains($host, '#') || str_contains($host, ' ')) {
            return null;
        }
        // A port, but not an IPv6 literal (which this app never serves).
        if (str_contains($host, ':')) {
            $host = substr($host, 0, (int) strpos($host, ':'));
        }
        $host = rtrim($host, '.');
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        if ($host === '' || strlen($host) > self::MAX_LENGTH) {
            return null;
        }
        // At least one dot: a single label is a machine on a local network, not
        // a domain anybody reaches a card at.
        if (!str_contains($host, '.')) {
            return null;
        }
        if (preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $host) !== 1) {
            return null;
        }
        // `--` is legal in a hostname but `..` is not, and neither is a label
        // that begins or ends with a hyphen.
        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63
                || str_starts_with($label, '-') || str_ends_with($label, '-')) {
                return null;
            }
        }
        return $host;
    }

    /**
     * The cache-key segment for a host.
     *
     * The page cache files a render under a path, and `mira-markt.de` as a path
     * segment looks like a FILENAME to the store — it would be written as a file
     * and then collide with the directory that host's sub-pages need. Mapping
     * dots to underscores is injective here because {@see normalize} rejects an
     * underscore outright.
     *
     * Mirrored by `cacheKeyForHost` in the card frontend.
     */
    public static function cacheSegment(string $host): string
    {
        return str_replace('.', '_', $host);
    }

    /**
     * Normalise a card's slug, or `null` when it cannot be one.
     *
     * Lower-case, digits and hyphens: it is the last segment of a URL on the
     * fallback domain, and a slug that needs escaping is a slug somebody will
     * mistype into a QR code.
     */
    public static function slug(?string $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $slug = strtolower(trim($value));
        if ($slug === '' || strlen($slug) > 80) {
            return null;
        }
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $slug) !== 1) {
            return null;
        }
        // Reserved: these are the card app's own paths, and a card sitting on
        // one of them would shadow the control plane or the setup wizard.
        $reserved = ['tds', 'install', 'api', 'robots', 'sitemap', 'favicon', 'en', 'assets', '_astro'];
        return in_array($slug, $reserved, true) ? null : $slug;
    }

    /**
     * Turn a name into a slug candidate — what the panel proposes, never what it
     * enforces. Returns `null` when nothing usable is left.
     */
    public static function slugify(string $name): ?string
    {
        $map = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue'];
        $slug = strtr($name, $map);
        $slug = strtolower($slug);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        if (strlen($slug) > 80) {
            $slug = rtrim(substr($slug, 0, 80), '-');
        }
        return self::slug($slug);
    }
}
