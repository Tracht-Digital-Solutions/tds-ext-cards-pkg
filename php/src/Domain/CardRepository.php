<?php
declare(strict_types=1);

namespace Tds\Ext\Cards\Domain;

use PDO;
use Tds\Ext\Cards\Support\CardBlocks;
use Tds\Ext\Cards\Support\CardDomain;

/**
 * Every query this module makes. Arrays, not entities — the house style here.
 *
 * Two things are worth knowing before changing anything:
 *
 * - **The public reads filter drafts in SQL, never in PHP.** A card that is not
 *   published must not be in the result set at all; a filter applied after the
 *   fetch is one `if` away from serving a draft on a customer's domain, and the
 *   mistake would be invisible in review.
 * - **Metadata and bytes are separate reads.** {@see assetMeta} answers the
 *   conditional request without touching the blob; only {@see assetBytes} loads
 *   it. A single read would pull a megabyte through PHP to produce a `304`.
 */
final class CardRepository
{
    /** The card's own columns, minus nothing — a card row is small. */
    private const CARD_COLUMNS = 'id, slug, domain, company_id, lang, display_name, role, '
        . 'company_name, tagline, phone, mobile, email, website, address_line, postal_code, '
        . 'city, country, accent, surface, theme, meta_description, blocks, draft, '
        . 'published_at, created_at, updated_at';

    /** Asset columns WITHOUT the blob. See the class docblock. */
    private const ASSET_META = 'id, card_id, kind, mime_type, size_bytes, width, height, updated_at';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /* --- admin reads ------------------------------------------------------ */

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        $sql = 'SELECT ' . self::CARD_COLUMNS . ' FROM card_page ORDER BY display_name, slug';
        $rows = $this->pdo->query($sql)?->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'shape'], $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::CARD_COLUMNS . ' FROM card_page WHERE slug = :s LIMIT 1');
        $stmt->execute([':s' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->shape($row);
    }

    public function count(): int
    {
        return (int) ($this->pdo->query('SELECT COUNT(*) FROM card_page')?->fetchColumn() ?: 0);
    }

    public function countPublished(): int
    {
        $sql = 'SELECT COUNT(*) FROM card_page WHERE draft = 0 AND published_at IS NOT NULL';
        return (int) ($this->pdo->query($sql)?->fetchColumn() ?: 0);
    }

    /* --- public reads ----------------------------------------------------- */

    /**
     * The published card on a host, or `null`.
     *
     * The host is normalised here as well as at the call site: this is the query
     * that decides which customer's page a visitor sees, and it must not depend
     * on a caller having remembered.
     */
    public function publicByDomain(string $host): ?array
    {
        $domain = CardDomain::normalize($host);
        if ($domain === null) {
            return null;
        }
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CARD_COLUMNS . ' FROM card_page '
            . 'WHERE domain = :d AND draft = 0 AND published_at IS NOT NULL LIMIT 1',
        );
        $stmt->execute([':d' => $domain]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->shape($row, true);
    }

    public function publicBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CARD_COLUMNS . ' FROM card_page '
            . 'WHERE slug = :s AND draft = 0 AND published_at IS NOT NULL LIMIT 1',
        );
        $stmt->execute([':s' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->shape($row, true);
    }

    /**
     * The index the card app needs: which cards exist, where, and how fresh.
     *
     * Deliberately NOT the full cards. The frontend uses this for its sitemap,
     * its cache-event resolution and its rebuild list — three jobs that need the
     * addresses and nothing else, several times per rebuild.
     *
     * @return list<array{slug: string, domain: string|null, updatedAt: string}>
     */
    public function publicIndex(): array
    {
        $sql = 'SELECT slug, domain, updated_at FROM card_page '
            . 'WHERE draft = 0 AND published_at IS NOT NULL ORDER BY slug';
        $rows = $this->pdo->query($sql)?->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn (array $r): array => [
            'slug' => (string) $r['slug'],
            'domain' => $r['domain'] === null ? null : (string) $r['domain'],
            'updatedAt' => (string) $r['updated_at'],
        ], $rows);
    }

    /* --- writes ----------------------------------------------------------- */

    /**
     * Insert or update a card by slug.
     *
     * @param array<string, mixed> $fields Already-validated values.
     */
    public function put(string $slug, array $fields): void
    {
        $columns = [
            'domain', 'company_id', 'lang', 'display_name', 'role', 'company_name', 'tagline',
            'phone', 'mobile', 'email', 'website', 'address_line', 'postal_code', 'city',
            'country', 'accent', 'surface', 'theme', 'meta_description', 'blocks', 'draft',
            'published_at',
        ];

        $params = [':slug' => $slug];
        foreach ($columns as $column) {
            $params[':' . $column] = $fields[$column] ?? null;
        }

        $insertCols = implode(', ', array_merge(['slug'], $columns));
        $insertVals = implode(', ', array_map(static fn (string $c): string => ':' . $c, array_merge(['slug'], $columns)));
        $updates = implode(', ', array_map(static fn (string $c): string => "{$c} = VALUES({$c})", $columns));

        $stmt = $this->pdo->prepare(
            "INSERT INTO card_page ({$insertCols}) VALUES ({$insertVals}) ON DUPLICATE KEY UPDATE {$updates}",
        );
        $stmt->execute($params);
    }

    public function delete(string $slug): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM card_page WHERE slug = :s');
        $stmt->execute([':s' => $slug]);
        return $stmt->rowCount() > 0;
    }

    /** Whether a domain is already claimed by a DIFFERENT card. */
    public function domainTaken(string $domain, string $exceptSlug): bool
    {
        $stmt = $this->pdo->prepare('SELECT slug FROM card_page WHERE domain = :d LIMIT 1');
        $stmt->execute([':d' => $domain]);
        $owner = $stmt->fetchColumn();
        return is_string($owner) && $owner !== $exceptSlug;
    }

    /* --- assets ----------------------------------------------------------- */

    /** @return array<string, mixed>|null Metadata only — no bytes. */
    public function assetMeta(int $cardId, string $kind): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::ASSET_META . ' FROM card_asset WHERE card_id = :c AND kind = :k LIMIT 1',
        );
        $stmt->execute([':c' => $cardId, ':k' => $kind]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null Metadata AND bytes. */
    public function assetBytes(int $cardId, string $kind): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::ASSET_META . ', content FROM card_asset WHERE card_id = :c AND kind = :k LIMIT 1',
        );
        $stmt->execute([':c' => $cardId, ':k' => $kind]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        // PDO hands a BLOB back as a stream on some drivers and a string on
        // others; normalise so callers never have to know which.
        $content = $row['content'];
        $row['content'] = is_resource($content) ? (string) stream_get_contents($content) : (string) $content;
        return $row;
    }

    public function putAsset(int $cardId, string $kind, string $mime, string $bytes, int $width, int $height): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO card_asset (card_id, kind, mime_type, size_bytes, width, height, content) '
            . 'VALUES (:c, :k, :m, :s, :w, :h, :b) '
            . 'ON DUPLICATE KEY UPDATE mime_type = VALUES(mime_type), size_bytes = VALUES(size_bytes), '
            . 'width = VALUES(width), height = VALUES(height), content = VALUES(content)',
        );
        $stmt->bindValue(':c', $cardId, PDO::PARAM_INT);
        $stmt->bindValue(':k', $kind);
        $stmt->bindValue(':m', $mime);
        $stmt->bindValue(':s', strlen($bytes), PDO::PARAM_INT);
        $stmt->bindValue(':w', $width, PDO::PARAM_INT);
        $stmt->bindValue(':h', $height, PDO::PARAM_INT);
        $stmt->bindValue(':b', $bytes, PDO::PARAM_LOB);
        $stmt->execute();
    }

    public function deleteAsset(int $cardId, string $kind): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM card_asset WHERE card_id = :c AND kind = :k');
        $stmt->execute([':c' => $cardId, ':k' => $kind]);
        return $stmt->rowCount() > 0;
    }

    /** Which kinds a card has, for the panel and for the renderer. @return list<string> */
    public function assetKinds(int $cardId): array
    {
        $stmt = $this->pdo->prepare('SELECT kind FROM card_asset WHERE card_id = :c ORDER BY kind');
        $stmt->execute([':c' => $cardId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * "Now", as the DATABASE tells it.
     *
     * Not `date()` and not `gmdate()`. The production database session runs in
     * Berlin time, so `created_at`/`updated_at` — which default to
     * `CURRENT_TIMESTAMP` — are local, while PHP's `gmdate` is UTC. A
     * `published_at` written from PHP would sit one or two hours away from the
     * row's own timestamps, and the card would look published in the future.
     * Asking the database costs one round trip and removes the question.
     */
    public function now(): string
    {
        $value = $this->pdo->query('SELECT NOW()')?->fetchColumn();
        return is_string($value) ? $value : date('Y-m-d H:i:s');
    }

    /* --- shaping ---------------------------------------------------------- */

    /**
     * One row as the API hands it out.
     *
     * `$public` hard-codes `draft => false` rather than reading the column. The
     * public routes already filter in SQL, so the value can only be false — and
     * writing it out means a future refactor that loses the filter produces an
     * obviously wrong payload instead of a quietly published draft.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shape(array $row, bool $public = false): array
    {
        $id = (int) $row['id'];
        $card = [
            'id' => $id,
            'slug' => (string) $row['slug'],
            'domain' => $row['domain'] === null ? null : (string) $row['domain'],
            'lang' => (string) $row['lang'],
            'displayName' => (string) $row['display_name'],
            'role' => $row['role'] === null ? null : (string) $row['role'],
            'companyName' => $row['company_name'] === null ? null : (string) $row['company_name'],
            'tagline' => $row['tagline'] === null ? null : (string) $row['tagline'],
            'phone' => $row['phone'] === null ? null : (string) $row['phone'],
            'mobile' => $row['mobile'] === null ? null : (string) $row['mobile'],
            'email' => $row['email'] === null ? null : (string) $row['email'],
            'website' => $row['website'] === null ? null : (string) $row['website'],
            'addressLine' => $row['address_line'] === null ? null : (string) $row['address_line'],
            'postalCode' => $row['postal_code'] === null ? null : (string) $row['postal_code'],
            'city' => $row['city'] === null ? null : (string) $row['city'],
            'country' => $row['country'] === null ? null : (string) $row['country'],
            'accent' => (string) $row['accent'],
            'surface' => (string) $row['surface'],
            'theme' => (string) $row['theme'],
            'metaDescription' => $row['meta_description'] === null ? null : (string) $row['meta_description'],
            'blocks' => CardBlocks::sanitize($row['blocks']),
            'assets' => $this->assetKinds($id),
            'updatedAt' => (string) $row['updated_at'],
        ];

        if ($public) {
            $card['draft'] = false;
            return $card;
        }

        $card['companyId'] = $row['company_id'] === null ? null : (int) $row['company_id'];
        $card['draft'] = (bool) $row['draft'];
        $card['publishedAt'] = $row['published_at'] === null ? null : (string) $row['published_at'];
        $card['createdAt'] = (string) $row['created_at'];
        return $card;
    }
}
