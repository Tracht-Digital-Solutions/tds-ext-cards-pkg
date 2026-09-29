<?php
declare(strict_types=1);

namespace Tds\Ext\Cards\Tests;

use PHPUnit\Framework\TestCase;
use Tds\Ext\Cards\Support\CardDomain;

/**
 * The normalisation that decides which card a visitor gets.
 *
 * This is the one piece of this module that has a twin in another repository:
 * `tds-card-frontend/src/lib/host.ts` reads a request's `Host` with exactly these
 * rules. If the two disagree by one dot or one capital letter, a card answers 404
 * on its own domain — and a 404 is never cached, so it keeps answering 404 while
 * every deployment marker and every test stays green.
 */
final class CardDomainTest extends TestCase
{
    /** @return array<string, array{string, ?string}> */
    public static function domains(): array
    {
        return [
            'plain' => ['mira-markt.de', 'mira-markt.de'],
            'upper case' => ['Mira-Markt.DE', 'mira-markt.de'],
            'surrounding space' => ['  mira-markt.de  ', 'mira-markt.de'],
            // `www.` is stripped so a request that arrives there anyway still
            // finds its card.
            'www' => ['www.mira-markt.de', 'mira-markt.de'],
            // A port appears in development and never in production; keeping it
            // would make a card resolvable locally and not on the host.
            'port' => ['mira-markt.de:4399', 'mira-markt.de'],
            'trailing dot' => ['mira-markt.de.', 'mira-markt.de'],
            'subdomain' => ['karte.tracht-digital.de', 'karte.tracht-digital.de'],
            'both' => ['WWW.Mira-Markt.de:443', 'mira-markt.de'],
            // A URL is a refusal, not something to strip: the operator meant
            // something we cannot guess, and guessing publishes a card at an
            // address they did not choose.
            'a url' => ['https://mira-markt.de', null],
            'a path' => ['mira-markt.de/impressum', null],
            'userinfo' => ['user@mira-markt.de', null],
            'a query' => ['mira-markt.de?x=1', null],
            // A single label is a machine on a local network, not a domain.
            'single label' => ['localhost', null],
            'empty' => ['', null],
            'space inside' => ['mira markt.de', null],
            'underscore' => ['mira_markt.de', null],
            'double dot' => ['mira..de', null],
            'leading hyphen in a label' => ['-mira.de', null],
            'trailing hyphen in a label' => ['mira-.de', null],
        ];
    }

    /** @dataProvider domains */
    public function testNormalize(string $input, ?string $expected): void
    {
        self::assertSame($expected, CardDomain::normalize($input));
    }

    public function testNormalizeIsIdempotent(): void
    {
        // The panel stores a normalised value and the frontend normalises what
        // the browser sent. Running the function twice must not move.
        foreach (self::domains() as [$input, $expected]) {
            if ($expected === null) {
                continue;
            }
            self::assertSame($expected, CardDomain::normalize($expected), $input);
        }
    }

    public function testNormalizeRefusesSomethingTooLongForDns(): void
    {
        $long = str_repeat('a.', 200) . 'de';
        self::assertNull(CardDomain::normalize($long));
    }

    public function testCacheSegmentHasNoDots(): void
    {
        // A final path segment that looks like a filename is stored AS a file by
        // the page cache, which then collides with the directory that host's
        // sub-pages need.
        self::assertSame('mira-markt_de', CardDomain::cacheSegment('mira-markt.de'));
        self::assertStringNotContainsString('.', CardDomain::cacheSegment('karte.tracht-digital.de'));
    }

    public function testCacheSegmentIsInjective(): void
    {
        // Mapping dots to underscores is only safe because `normalize` rejects an
        // underscore, so no two distinct hosts can land on one segment.
        self::assertNull(CardDomain::normalize('mira_markt.de'));
    }

    /** @return array<string, array{string, ?string}> */
    public static function slugs(): array
    {
        return [
            'plain' => ['mira-markt', 'mira-markt'],
            'upper case' => ['Mira-Markt', 'mira-markt'],
            'digits' => ['karte-2026', 'karte-2026'],
            'empty' => ['', null],
            'a slash' => ['mira/markt', null],
            'a dot' => ['mira.markt', null],
            'leading hyphen' => ['-mira', null],
            'trailing hyphen' => ['mira-', null],
            'umlaut' => ['grün', null],
            // Reserved: a card here would shadow the app's own paths.
            'the control plane' => ['tds', null],
            'the wizard' => ['install', null],
            'the sitemap' => ['sitemap', null],
            'the english prefix' => ['en', null],
        ];
    }

    /** @dataProvider slugs */
    public function testSlug(string $input, ?string $expected): void
    {
        self::assertSame($expected, CardDomain::slug($input));
    }

    public function testSlugifyProposesSomethingUsable(): void
    {
        self::assertSame('mira-markt', CardDomain::slugify('Mira Markt'));
        self::assertSame('gruener-hof', CardDomain::slugify('Grüner Hof'));
        self::assertSame('nordholz-tischlerei-gmbh-co', CardDomain::slugify('Nordholz Tischlerei GmbH & Co.'));
        // Nothing usable left is a refusal, not an empty slug — the caller then
        // asks the operator instead of creating a card at `/`.
        self::assertNull(CardDomain::slugify('///'));
        self::assertNull(CardDomain::slugify(''));
    }

    public function testSlugifyNeverProposesAReservedWord(): void
    {
        self::assertNull(CardDomain::slugify('Install'));
    }
}
