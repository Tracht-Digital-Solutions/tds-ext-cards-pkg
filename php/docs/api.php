<?php
/**
 * API documentation for this module's routes — consumed through `ApiDocSource`
 * and rendered in the admin frontend's API reference (`GET /wiki.json`).
 *
 * `CardsApiDocsTest` asserts BOTH directions: a route without a description and
 * a description without a route both fail the suite. That is the point — prose
 * next to code rots, and a reference full of confident, wrong detail is worse
 * than the bare route list it replaced.
 *
 * `pattern` must match the Slim pattern in `register()` VERBATIM, inline regex
 * included: it is the join key for the route introspection, so a prettified path
 * produces an orphan doc AND an undocumented route rather than an error.
 */

declare(strict_types=1);

return [
    /* --- public ------------------------------------------------------- */
    [
        'method' => 'GET',
        'pattern' => '/content/card',
        'summary' => 'Eine veröffentlichte Visitenkarte, per Host oder per Adresse',
        'description' => 'Die Route, mit der `tds-card-frontend` beim Rendern entscheidet, welche '
            . 'Karte ein Besucher sieht. `host` gewinnt, `slug` ist der Rückfall auf '
            . '`karte.tracht-digital.de`. Entwürfe sind hier nicht erreichbar — der Filter steht '
            . 'in SQL, nicht im PHP. Antwortet mit 404 und `{card: null}`, wenn nichts passt; '
            . 'eine 404 wird nie gecacht, also wirkt eine Veröffentlichung sofort.',
        'auth' => 'public',
        'tag' => 'Öffentlich',
        'params' => [
            ['name' => 'host', 'in' => 'query', 'description' => 'Der Host aus der Anfrage, ohne `www.` und ohne Port.'],
            ['name' => 'slug', 'in' => 'query', 'description' => 'Die Adresse auf der Rückfall-Domain.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => '`{card: {...}}`'],
            ['status' => 404, 'description' => '`{card: null}` — unbekannt oder nicht veröffentlicht'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/content/cards',
        'summary' => 'Index aller veröffentlichten Karten (nur Adressen)',
        'description' => 'Absichtlich nicht die ganzen Karten: das Frontend löst daraus seine '
            . 'Sitemap, seine Cache-Ereignisse und seine Rebuild-Liste auf — mehrmals je Rebuild. '
            . 'Zählt im Einrichtungsassistenten als Verbindungsprobe.',
        'auth' => 'public',
        'tag' => 'Öffentlich',
        'responses' => [
            ['status' => 200, 'description' => '`{cards: [{slug, domain, updatedAt}]}`'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/content/card/{slug:[a-z0-9-]+}/{kind:portrait|logo}',
        'summary' => 'Portrait oder Logo einer veröffentlichten Karte',
        'description' => 'Ohne Anmeldung, weil ein `<img>` von einer anderen Domain ohnehin keine '
            . 'Anmeldedaten sendet. Antwortet `304` über ein schwaches ETag, ohne den Blob zu '
            . 'lesen, und setzt `X-Content-Type-Options: nosniff` — die Bytes kommen von '
            . 'Benutzern und liegen auf derselben Domain wie das Sitzungs-Cookie.',
        'auth' => 'public',
        'tag' => 'Öffentlich',
        'params' => [
            ['name' => 'slug', 'in' => 'path', 'description' => 'Die Adresse der Karte.'],
            ['name' => 'kind', 'in' => 'path', 'description' => '`portrait` oder `logo`.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => 'Die Bilddaten'],
            ['status' => 304, 'description' => 'Unverändert'],
            ['status' => 404, 'description' => 'Karte oder Bild fehlt'],
        ],
    ],

    /* --- Verwaltung: Karten ------------------------------------------- */
    [
        'method' => 'GET',
        'pattern' => '/cards',
        'summary' => 'Alle Karten, Entwürfe eingeschlossen',
        'auth' => 'permission',
        'permission' => 'cards:read',
        'tag' => 'Karten',
        'responses' => [['status' => 200, 'description' => '`{cards: [...]}`']],
    ],
    [
        'method' => 'POST',
        'pattern' => '/cards',
        'summary' => 'Eine Karte anlegen',
        'description' => 'Ohne `slug` wird einer aus `displayName` oder `companyName` '
            . 'vorgeschlagen — so legt die Bestellübergabe aus dem Shop eine Karte an, die nur '
            . 'einen Firmennamen kennt. Die Karte ist immer erst ein Entwurf.',
        'auth' => 'permission',
        'permission' => 'cards:write',
        'tag' => 'Karten',
        'params' => [
            ['name' => 'slug', 'in' => 'body', 'description' => 'Adresse auf der Rückfall-Domain; optional.'],
            ['name' => 'displayName', 'in' => 'body', 'description' => 'Der Name auf der Karte. Pflicht.'],
            ['name' => 'domain', 'in' => 'body', 'description' => 'Die eigene Domain; optional, muss frei sein.'],
            ['name' => 'blocks', 'in' => 'body', 'description' => 'Die freien Blöcke; ungültige werden verworfen.'],
        ],
        'responses' => [
            ['status' => 201, 'description' => '`{card, cache_status, cached, ...}`'],
            ['status' => 409, 'description' => 'Die Adresse ist vergeben'],
            ['status' => 422, 'description' => 'Adresse, Name, Farbe, E-Mail oder Domain unbrauchbar'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/cards/{slug:[a-z0-9-]+}',
        'summary' => 'Eine Karte zum Bearbeiten',
        'auth' => 'permission',
        'permission' => 'cards:read',
        'tag' => 'Karten',
        'params' => [['name' => 'slug', 'in' => 'path', 'description' => 'Die Adresse der Karte.']],
        'responses' => [
            ['status' => 200, 'description' => '`{card}`'],
            ['status' => 404, 'description' => 'Unbekannt'],
        ],
    ],
    [
        'method' => 'PUT',
        'pattern' => '/cards/{slug:[a-z0-9-]+}',
        'summary' => 'Eine Karte speichern',
        'description' => 'Ein teilweiser Rumpf ÄNDERT, er leert nicht: ein fehlendes Feld behält '
            . 'seinen Wert. Ein veröffentlichter Datensatz behält sein `published_at`, damit '
            . 'erneutes Speichern die Karte nicht neu datiert.',
        'auth' => 'permission',
        'permission' => 'cards:write',
        'tag' => 'Karten',
        'params' => [['name' => 'slug', 'in' => 'path', 'description' => 'Die Adresse der Karte.']],
        'responses' => [
            ['status' => 200, 'description' => '`{card, cache_status, cached, ...}`'],
            ['status' => 404, 'description' => 'Unbekannt'],
            ['status' => 422, 'description' => 'Ein Wert ist unbrauchbar'],
        ],
    ],
    [
        'method' => 'DELETE',
        'pattern' => '/cards/{slug:[a-z0-9-]+}',
        'summary' => 'Eine Karte löschen',
        'description' => 'Die Bilder gehen über den Fremdschlüssel mit.',
        'auth' => 'permission',
        'permission' => 'cards:write',
        'tag' => 'Karten',
        'params' => [['name' => 'slug', 'in' => 'path', 'description' => 'Die Adresse der Karte.']],
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true, cache_status, ...}`'],
            ['status' => 404, 'description' => 'Unbekannt'],
        ],
    ],

    /* --- Verwaltung: Bilder ------------------------------------------- */
    [
        'method' => 'GET',
        'pattern' => '/cards/{slug:[a-z0-9-]+}/image/{kind:portrait|logo}',
        'summary' => 'Vorschau eines Bildes, auch im Entwurf',
        'description' => 'Der Unterschied zur öffentlichen Route ist genau das: hier ist ein '
            . 'Entwurf sichtbar, denn das ist der Zweck einer Vorschau.',
        'auth' => 'permission',
        'permission' => 'cards:read',
        'tag' => 'Bilder',
        'params' => [
            ['name' => 'slug', 'in' => 'path', 'description' => 'Die Adresse der Karte.'],
            ['name' => 'kind', 'in' => 'path', 'description' => '`portrait` oder `logo`.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => 'Die Bilddaten'],
            ['status' => 404, 'description' => 'Karte oder Bild fehlt'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/cards/{slug:[a-z0-9-]+}/image/{kind:portrait|logo}',
        'summary' => 'Portrait oder Logo hochladen (multipart, Feld `file`)',
        'description' => 'Der angegebene Medientyp wird ignoriert; `getimagesizefromstring()` liest '
            . 'die Bytes. PNG, JPEG, WebP — **kein SVG**: das ist ein Dokument und kann ein Skript '
            . 'tragen. Es wird serverseitig nicht skaliert, weil `ext-gd` auf dem Host nicht '
            . 'garantiert ist; das Panel verkleinert vorher im Browser.',
        'auth' => 'permission',
        'permission' => 'cards:write',
        'tag' => 'Bilder',
        'params' => [
            ['name' => 'slug', 'in' => 'path', 'description' => 'Die Adresse der Karte.'],
            ['name' => 'kind', 'in' => 'path', 'description' => '`portrait` oder `logo`.'],
            ['name' => 'file', 'in' => 'body', 'description' => 'Die Datei, maximal 2 MB.'],
        ],
        'responses' => [
            ['status' => 201, 'description' => '`{ok: true, image: {mime, width, height}}`'],
            ['status' => 400, 'description' => 'Keine Datei'],
            ['status' => 413, 'description' => 'Größer als 2 MB'],
            ['status' => 415, 'description' => 'Kein PNG, JPEG oder WebP'],
        ],
    ],
    [
        'method' => 'DELETE',
        'pattern' => '/cards/{slug:[a-z0-9-]+}/image/{kind:portrait|logo}',
        'summary' => 'Portrait oder Logo entfernen',
        'auth' => 'permission',
        'permission' => 'cards:write',
        'tag' => 'Bilder',
        'params' => [
            ['name' => 'slug', 'in' => 'path', 'description' => 'Die Adresse der Karte.'],
            ['name' => 'kind', 'in' => 'path', 'description' => '`portrait` oder `logo`.'],
        ],
        'responses' => [['status' => 200, 'description' => '`{ok: true, deleted}`']],
    ],

    /* --- Verwaltung: Betrieb ------------------------------------------ */
    [
        'method' => 'GET',
        'pattern' => '/cards/summary',
        'summary' => 'Kennzahlen für das Dashboard-Widget',
        'auth' => 'permission',
        'permission' => 'cards:read',
        'tag' => 'Betrieb',
        'responses' => [['status' => 200, 'description' => '`{total, published}`']],
    ],
    [
        'method' => 'POST',
        'pattern' => '/cards/{slug:[a-z0-9-]+}/cache/rebuild',
        'summary' => 'Die Seiten einer Karte neu bauen lassen',
        'description' => 'Sendet ein Ereignis, keine Pfade: welche Adressen eine Karte besitzt, '
            . 'weiß das Frontend. Der Status sagt, ob wirklich eine Anfrage rausging — '
            . '`not_configured` ist der normale Zustand vor der Kopplung.',
        'auth' => 'permission',
        'permission' => 'cards:write',
        'tag' => 'Betrieb',
        'params' => [['name' => 'slug', 'in' => 'path', 'description' => 'Die Adresse der Karte.']],
        'responses' => [
            ['status' => 202, 'description' => 'Angefragt'],
            ['status' => 404, 'description' => 'Unbekannt'],
            ['status' => 503, 'description' => 'Kein Ziel konfiguriert'],
            ['status' => 502, 'description' => 'Das Ziel hat nicht angenommen'],
        ],
    ],
    [
        'method' => 'GET',
        'pattern' => '/cards/connection',
        'summary' => 'Die Verbindung zur Kartenseite',
        'description' => 'Es gibt genau eine: eine App bedient alle Kundendomains, also auch nur '
            . 'einen Schlüssel und eine Kopplung.',
        'auth' => 'permission',
        'permission' => 'cards:read',
        'tag' => 'Betrieb',
        'responses' => [
            ['status' => 200, 'description' => '`{connection}`'],
            ['status' => 404, 'description' => 'Nicht gekoppelt'],
            ['status' => 503, 'description' => 'Der Kopplungsdienst fehlt'],
        ],
    ],
    [
        'method' => 'DELETE',
        'pattern' => '/cards/connection',
        'summary' => 'Die Verbindung trennen',
        'auth' => 'permission',
        'permission' => 'cards:write',
        'tag' => 'Betrieb',
        'responses' => [
            ['status' => 200, 'description' => '`{ok: true, deleted}`'],
            ['status' => 503, 'description' => 'Der Kopplungsdienst fehlt'],
        ],
    ],
    [
        'method' => 'POST',
        'pattern' => '/cards/connection/pairing',
        'summary' => 'Eine Kopplung für die Kartenseite anlegen',
        'description' => 'Der `origin` ist die Rückfall-Domain, nicht eine Kundendomain: die '
            . 'Kundendomains sind Aliase derselben App und rufen die API nie aus einem Browser. '
            . 'Hinter Plesk muss der Origin `https://` sein, sonst 422.',
        'auth' => 'permission',
        'permission' => 'cards:write',
        'tag' => 'Betrieb',
        'params' => [['name' => 'origin', 'in' => 'body', 'description' => 'Zum Beispiel `https://karte.tracht-digital.de`.']],
        'responses' => [
            ['status' => 201, 'description' => 'Die Kopplungsdaten, einmalig'],
            ['status' => 422, 'description' => 'Der Origin ist unbrauchbar'],
            ['status' => 503, 'description' => 'Die Kopplung konnte nicht angelegt werden'],
        ],
    ],
];
