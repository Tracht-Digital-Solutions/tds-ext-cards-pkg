<?php
declare(strict_types=1);

namespace Tds\Ext\Cards\Support;

/**
 * What a card's portrait or logo may be.
 *
 * Modelled on `tds-auth-api`'s `AvatarService`, including the two decisions that
 * are easy to get backwards:
 *
 * - **The declared content type is ignored.** It is whatever the uploader said.
 *   `getimagesizefromstring()` reads the bytes, which is the only claim worth
 *   believing.
 * - **SVG is excluded on purpose.** It is a document, not an image: it can carry
 *   a `<script>`, and these bytes are served from an origin that also serves the
 *   card. A stored script there runs on the customer's own domain.
 *
 * There is no server-side resizing. The production host does not guarantee
 * `ext-gd`, so a resize would work in development and throw on the host; the
 * panel downscales in the browser before uploading instead.
 *
 * Pure functions, no PDO: the tests run without a database.
 */
final class CardImage
{
    /** 2 MB, matching the avatar cap — a portrait on a card is not a print asset. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /** The kinds a card stores. One row each, replaced on re-upload. */
    public const KINDS = ['portrait', 'logo'];

    /** @var array<int, string> IMAGETYPE_* to the type we serve it as. */
    private const ALLOWED = [
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    public static function kindValid(string $kind): bool
    {
        return in_array($kind, self::KINDS, true);
    }

    /**
     * Read the bytes and report what they actually are.
     *
     * @return array{mime: string, width: int, height: int}|null Null when the
     *         bytes are not an image of an accepted type.
     */
    public static function sniff(string $bytes): ?array
    {
        if ($bytes === '') {
            return null;
        }
        // Suppressed: a malformed upload makes this emit a warning, and the
        // suite runs with failOnWarning. The null return IS the error path.
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return null;
        }
        $type = (int) ($info[2] ?? 0);
        if (!isset(self::ALLOWED[$type])) {
            return null;
        }
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 1 || $height < 1) {
            return null;
        }
        return ['mime' => self::ALLOWED[$type], 'width' => $width, 'height' => $height];
    }

    /**
     * The weak validator for a stored image.
     *
     * Weak (`W/`) because it is derived from the row's identity and timestamp
     * rather than the bytes: the point is to answer `304` WITHOUT loading the
     * blob, and hashing the content would defeat that.
     */
    public static function etag(int $cardId, string $kind, string $updatedAt): string
    {
        return 'W/"' . md5($cardId . '|' . $kind . '|' . $updatedAt) . '"';
    }
}
