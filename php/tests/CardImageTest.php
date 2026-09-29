<?php
declare(strict_types=1);

namespace Tds\Ext\Cards\Tests;

use PHPUnit\Framework\TestCase;
use Tds\Ext\Cards\Support\CardImage;

/**
 * What a card's portrait may be.
 *
 * The bytes here are the smallest valid files of each type, written out as hex
 * rather than generated: this suite must not depend on `ext-gd`, which is the
 * very extension the production host does not guarantee.
 */
final class CardImageTest extends TestCase
{
    /** A 1×1 PNG. */
    private const PNG = '89504e470d0a1a0a0000000d49484452000000010000000108060000001f15c4890000000a4944415478'
        . '9c6300010000050001'
        . '0d0a2db40000000049454e44ae426082';

    /** A 1×1 GIF — a real image, and deliberately not on the allow-list. */
    private const GIF = '47494638396101000100800000000000ffffff21f90401000000002c00000000010001000002024401003b';

    private static function bytes(string $hex): string
    {
        return (string) hex2bin($hex);
    }

    public function testReadsAPng(): void
    {
        $sniffed = CardImage::sniff(self::bytes(self::PNG));
        self::assertNotNull($sniffed);
        self::assertSame('image/png', $sniffed['mime']);
        self::assertSame(1, $sniffed['width']);
        self::assertSame(1, $sniffed['height']);
    }

    public function testRefusesAnImageTypeThatIsNotOnTheList(): void
    {
        // A GIF is a valid image and still not something a card serves. The check
        // is an allow-list, not a "looks like an image" test.
        self::assertNull(CardImage::sniff(self::bytes(self::GIF)));
    }

    public function testRefusesSvg(): void
    {
        // SVG is a document, not an image: it can carry a script, and these bytes
        // are served from the API origin, next to the session cookie.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
        self::assertNull(CardImage::sniff($svg));
    }

    public function testIgnoresWhatTheUploaderClaimed(): void
    {
        // A text file renamed to .png with a declared image type is still a text
        // file. Only the bytes are believed.
        self::assertNull(CardImage::sniff('GIF89a but actually not'));
        self::assertNull(CardImage::sniff('not an image at all'));
        self::assertNull(CardImage::sniff(''));
    }

    public function testKnowsItsTwoKinds(): void
    {
        self::assertTrue(CardImage::kindValid('portrait'));
        self::assertTrue(CardImage::kindValid('logo'));
        self::assertFalse(CardImage::kindValid('banner'));
        self::assertFalse(CardImage::kindValid(''));
    }

    public function testTheEtagIsWeakAndChangesWithTheTimestamp(): void
    {
        // Weak on purpose: it is derived from the row's identity, not its bytes,
        // so a conditional request can be answered WITHOUT loading the blob —
        // which is the entire reason the metadata read is separate.
        $a = CardImage::etag(7, 'portrait', '2026-09-29 08:00:00');
        $b = CardImage::etag(7, 'portrait', '2026-09-29 08:00:01');
        $c = CardImage::etag(8, 'portrait', '2026-09-29 08:00:00');
        $d = CardImage::etag(7, 'logo', '2026-09-29 08:00:00');

        self::assertStringStartsWith('W/"', $a);
        self::assertNotSame($a, $b);
        self::assertNotSame($a, $c);
        self::assertNotSame($a, $d);
        self::assertSame($a, CardImage::etag(7, 'portrait', '2026-09-29 08:00:00'));
    }

    public function testTheSizeCapIsTheAvatarCap(): void
    {
        self::assertSame(2 * 1024 * 1024, CardImage::MAX_BYTES);
    }
}
