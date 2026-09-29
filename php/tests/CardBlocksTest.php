<?php
declare(strict_types=1);

namespace Tds\Ext\Cards\Tests;

use PHPUnit\Framework\TestCase;
use Tds\Ext\Cards\Support\CardBlocks;

/**
 * The server's copy of the block model.
 *
 * It hand-mirrors `tds-shared/schemas/cardBlocks`, so the assertions here are
 * deliberately the same cases as `cardBlocks.test.ts`. The duplication is the
 * point: the API cannot import TypeScript, and trusting the panel's validation
 * means any client that skips it writes whatever it likes into a page rendered on
 * a customer's own domain.
 */
final class CardBlocksTest extends TestCase
{
    public function testAcceptsTheFourSchemesACardNeeds(): void
    {
        self::assertTrue(CardBlocks::hrefOk('https://mira-markt.de'));
        self::assertTrue(CardBlocks::hrefOk('http://mira-markt.de'));
        self::assertTrue(CardBlocks::hrefOk('mailto:hallo@mira-markt.de'));
        self::assertTrue(CardBlocks::hrefOk('tel:+4915112345678'));
    }

    public function testRejectsAScriptUrl(): void
    {
        // The whole reason the check exists: a card renders on the customer's own
        // domain, where a pasted script runs unnoticed.
        self::assertFalse(CardBlocks::hrefOk('javascript:alert(1)'));
        self::assertFalse(CardBlocks::hrefOk('JavaScript:alert(1)'));
        self::assertFalse(CardBlocks::hrefOk('data:text/html,<script>alert(1)</script>'));
    }

    public function testRejectsARelativeAndAProtocolRelativeTarget(): void
    {
        // A card is one page, so a relative link is always a mistake — and
        // allowing it would allow `//evil.example`, which a browser reads as
        // absolute.
        self::assertFalse(CardBlocks::hrefOk('/impressum'));
        self::assertFalse(CardBlocks::hrefOk('//evil.example'));
    }

    public function testAcceptsAnEmptyTargetBecauseAnEditorIsStillTyping(): void
    {
        // Same decision as the TypeScript schema: a link just inserted has no
        // target yet, and refusing it makes the card unsavable while somebody
        // works on it. An empty target never reaches a page.
        self::assertTrue(CardBlocks::hrefOk(''));
        self::assertTrue(CardBlocks::hrefOk('   '));
    }

    public function testRejectsASchemeWithNoHostBehindIt(): void
    {
        // `https://` on its own is what a placeholder in a form looks like. It
        // links nowhere in every browser.
        self::assertFalse(CardBlocks::hrefOk('https://'));
        self::assertFalse(CardBlocks::hrefOk('mailto:'));
    }

    public function testReadsAWrappedDocumentAndABareList(): void
    {
        $block = ['type' => 'heading', 'text' => 'Kontakt'];
        self::assertCount(1, CardBlocks::sanitize(['version' => 1, 'blocks' => [$block]]));
        self::assertCount(1, CardBlocks::sanitize([$block]));
        self::assertCount(1, CardBlocks::sanitize(json_encode(['blocks' => [$block]])));
    }

    public function testDropsOnlyTheBrokenBlock(): void
    {
        $blocks = CardBlocks::sanitize([
            ['type' => 'heading', 'text' => 'Kontakt'],
            ['type' => 'nope', 'text' => '?'],
            'not even an array',
            ['type' => 'text', 'text' => 'Hallo'],
        ]);

        self::assertSame(['heading', 'text'], array_column($blocks, 'type'));
    }

    public function testDropsOnlyTheBrokenLinkInsideABlock(): void
    {
        // A link group survives one bad row. Losing the whole group would lose
        // the card's reason to exist because of one paste.
        $blocks = CardBlocks::sanitize([[
            'type' => 'links',
            'items' => [
                ['label' => 'Gut', 'href' => 'https://mira-markt.de'],
                ['label' => 'Böse', 'href' => 'javascript:alert(1)'],
            ],
        ]]);

        self::assertCount(1, $blocks[0]['items']);
        self::assertSame('Gut', $blocks[0]['items'][0]['label']);
    }

    public function testAnswersWithNothingForJunkInsteadOfThrowing(): void
    {
        self::assertSame([], CardBlocks::sanitize('{ not json'));
        self::assertSame([], CardBlocks::sanitize(['nope' => true]));
        self::assertSame([], CardBlocks::sanitize(null));
        self::assertSame([], CardBlocks::sanitize(42));
    }

    public function testKeepsAnUnknownIconOutRatherThanRenderingNothing(): void
    {
        // The vocabulary is closed because the renderer draws a glyph per name;
        // an unknown name draws nothing at all, which looks like a broken card
        // rather than a rejected value.
        $blocks = CardBlocks::sanitize([[
            'type' => 'links',
            'items' => [['label' => 'x', 'href' => 'tel:+49123', 'icon' => 'skull']],
        ]]);

        self::assertNull($blocks[0]['items'][0]['icon']);
    }

    public function testDropsAnUnknownNetwork(): void
    {
        $blocks = CardBlocks::sanitize([[
            'type' => 'socials',
            'items' => [
                ['network' => 'linkedin', 'href' => 'https://linkedin.com/in/x'],
                ['network' => 'myspace', 'href' => 'https://myspace.com/x'],
            ],
        ]]);

        self::assertCount(1, $blocks[0]['items']);
    }

    public function testCapsTheNumberOfBlocks(): void
    {
        $many = array_fill(0, CardBlocks::MAX_BLOCKS + 20, ['type' => 'divider']);
        self::assertCount(CardBlocks::MAX_BLOCKS, CardBlocks::sanitize($many));
    }

    public function testEncodeRoundTrips(): void
    {
        $blocks = CardBlocks::sanitize([['type' => 'text', 'text' => 'Grüße aus Köln']]);
        $json = CardBlocks::encode($blocks);

        // Unescaped: the column is utf8mb4 and `ü` in a stored page is a
        // needless difference between what was typed and what is read back.
        self::assertStringContainsString('Grüße', $json);
        self::assertSame($blocks, CardBlocks::sanitize($json));
    }

    public function testTruncatesRatherThanRefusingOverlongText(): void
    {
        $blocks = CardBlocks::sanitize([['type' => 'heading', 'text' => str_repeat('a', 500)]]);
        self::assertSame(160, mb_strlen($blocks[0]['text']));
    }
}
