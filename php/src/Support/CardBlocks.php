<?php
declare(strict_types=1);

namespace Tds\Ext\Cards\Support;

/**
 * The server's copy of the free-block model.
 *
 * `tds-shared/schemas/cardBlocks` is the contract; this hand-mirrors it, the same
 * arrangement `BlogPostCreateSchema` has with tds-content-api's validator. The
 * duplication is deliberate and the alternative is worse: the API cannot import
 * TypeScript, and trusting the panel's validation means any client that skips it
 * writes whatever it likes into a page rendered on a customer's domain.
 *
 * What this must agree with, exactly:
 *
 * - the six block types and their fields
 * - an `href` may be EMPTY (a link the author just inserted) but anything else
 *   must be `http(s)`, `mailto:` or `tel:` — `javascript:` and `data:` are a
 *   stored script on a page nobody is watching
 * - the closed vocabularies for `icon` and `network`
 *
 * Sanitising rather than rejecting: an invalid block is dropped and the save
 * proceeds. A whole card refused because one link is malformed teaches an editor
 * to stop saving, and the panel's own validation is what tells them why.
 */
final class CardBlocks
{
    public const MAX_BLOCKS = 60;

    private const ICONS = ['link', 'phone', 'mail', 'map', 'calendar', 'download', 'shop', 'chat'];

    private const NETWORKS = [
        'linkedin', 'xing', 'instagram', 'facebook', 'youtube', 'github', 'whatsapp', 'website',
    ];

    private const SCHEMES = ['http://', 'https://', 'mailto:', 'tel:'];

    /** Whether a link target may be stored. Mirrors `isSafeHref` in tds-shared. */
    public static function hrefOk(string $href): bool
    {
        $value = trim($href);
        if ($value === '') {
            // Empty is in-progress, not invalid — see the class docblock.
            return true;
        }
        if (strlen($value) > 600) {
            return false;
        }
        $lower = strtolower($value);
        foreach (self::SCHEMES as $scheme) {
            if (str_starts_with($lower, $scheme)) {
                // `https://` alone is a scheme with no host: it parses as a URL
                // in some readings and links nowhere in every browser.
                return strlen($value) > strlen($scheme);
            }
        }
        return false;
    }

    /**
     * Validate and normalise a decoded block list.
     *
     * @param mixed $raw The decoded JSON: either `{version, blocks}` or a bare list.
     * @return list<array<string, mixed>> Only the blocks that survived.
     */
    public static function sanitize(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = $decoded === null ? [] : $decoded;
        }
        if (is_array($raw) && isset($raw['blocks']) && is_array($raw['blocks'])) {
            $raw = $raw['blocks'];
        }
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach (array_slice(array_values($raw), 0, self::MAX_BLOCKS) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $block = self::one($candidate);
            if ($block !== null) {
                $out[] = $block;
            }
        }
        return $out;
    }

    /** Encode a sanitised list back into what the column holds. */
    public static function encode(array $blocks): string
    {
        return json_encode(
            ['version' => 1, 'blocks' => array_values($blocks)],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }

    /** @return array<string, mixed>|null */
    private static function one(array $b): ?array
    {
        $type = is_string($b['type'] ?? null) ? $b['type'] : '';

        return match ($type) {
            'heading' => ['type' => 'heading', 'text' => self::text($b['text'] ?? '', 160)],
            'text' => ['type' => 'text', 'text' => self::text($b['text'] ?? '', 2000)],
            'divider' => ['type' => 'divider'],
            'links' => [
                'type' => 'links',
                'label' => self::nullableText($b['label'] ?? null, 120),
                'items' => self::links($b['items'] ?? []),
            ],
            'socials' => ['type' => 'socials', 'items' => self::socials($b['items'] ?? [])],
            'hours' => [
                'type' => 'hours',
                'label' => self::nullableText($b['label'] ?? null, 120),
                'rows' => self::hours($b['rows'] ?? []),
            ],
            default => null,
        };
    }

    /** @return list<array<string, mixed>> */
    private static function links(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach (array_slice(array_values($items), 0, 30) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $href = self::text($item['href'] ?? '', 600);
            if (!self::hrefOk($href)) {
                continue;
            }
            $icon = is_string($item['icon'] ?? null) && in_array($item['icon'], self::ICONS, true)
                ? $item['icon']
                : null;
            $out[] = [
                'label' => self::text($item['label'] ?? '', 120),
                'href' => $href,
                'note' => self::nullableText($item['note'] ?? null, 160),
                'icon' => $icon,
            ];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function socials(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }
        $out = [];
        foreach (array_slice(array_values($items), 0, 12) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $network = is_string($item['network'] ?? null) ? $item['network'] : '';
            $href = self::text($item['href'] ?? '', 600);
            if (!in_array($network, self::NETWORKS, true) || !self::hrefOk($href)) {
                continue;
            }
            $out[] = ['network' => $network, 'href' => $href];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function hours(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach (array_slice(array_values($rows), 0, 14) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'days' => self::text($row['days'] ?? '', 60),
                'time' => self::text($row['time'] ?? '', 60),
            ];
        }
        return $out;
    }

    private static function text(mixed $value, int $max): string
    {
        if (!is_string($value)) {
            return '';
        }
        return mb_substr($value, 0, $max);
    }

    private static function nullableText(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : mb_substr($trimmed, 0, $max);
    }
}
