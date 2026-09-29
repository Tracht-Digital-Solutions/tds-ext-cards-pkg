# tds-ext-cards-pkg

Visitenkarten-Seiten: eine Linktree-Seite je Kunde, im Panel angelegt und von
[`tds-card-frontend`](https://github.com/Tracht-Digital-Solutions) auf der
eigenen Domain des Kunden ausgeliefert.

Eine TDS-Panel-Erweiterung mit zwei Hälften in einem Repository: das
Frontend-Manifest samt Oberfläche (npm, `@tracht-digital-solutions/tds-ext-cards`)
und das Backend-Modul (Composer, `tracht-digital-solutions/tds-ext-cards`).

## Was eine Karte ist

Feste Felder plus freie Blöcke.

Die **festen Felder** sind Spalten auf `card_page`: Name, Funktion, Firma,
Kurzsatz, Telefon, Mobil, E-Mail, Website, Anschrift, Farbe, Fläche, hell oder
dunkel. Sie sind Spalten, weil eine vCard und ein JSON-LD-`Person` ihre Werte
namentlich brauchen und aus einem freien Block nicht zurückgewonnen werden
können.

Die **freien Blöcke** sind alles darüber hinaus — Linkgruppen, Überschriften,
Text, Öffnungszeiten, Profile, Trennlinien. Sie liegen als JSON in
`card_page.blocks`, und ihr Modell steht in
`@tracht-digital-solutions/tds-shared/schemas` (`cardBlocks`), nicht hier: den
Editor stellt diese Erweiterung, den Renderer das Kartenfrontend, und die beiden
werden getrennt released. Ein Modell in einem von beiden würde auseinanderlaufen.
`php/src/Support/CardBlocks.php` spiegelt es von Hand für den Server — dieselbe
Abmachung, die `BlogPostCreateSchema` mit dem Validator der Content-API hat.

## Zwei Adressen, mit Absicht

Jede Karte ist ab dem Anlegen unter `karte.tracht-digital.de/<adresse>`
erreichbar. Die eigene Domain kommt dazu, sobald sie im Hosting als Alias
eingerichtet ist. Eine Oberfläche, die nur die Domain zeigt, ließe eine Karte so
lange unveröffentlichbar aussehen, wie fremdes DNS braucht.

`Support/CardDomain::normalize()` ist die eine Funktion mit einem Zwilling in
einem anderen Repository (`tds-card-frontend/src/lib/host.ts`). Sie entscheidet,
welche Karte ein Besucher sieht. Weichen die beiden um einen Punkt, einen Port
oder ein `www.` voneinander ab, antwortet eine Karte auf ihrer eigenen Domain
mit 404 — und eine 404 wird nie gecacht, also bleibt es dabei, während jeder
Deployment-Stempel grün ist.

## Keine Site-Registry

Das Website-CMS hat eine `cms_site`-Tabelle, weil dort mehrere ausgelieferte
Seiten je eigene Inhalte besitzen. Karten haben die umgekehrte Form: **eine**
App beantwortet jede Kundendomain und wählt die Karte am `Host`. Also gibt es
genau eine Verbindung (`cards`/`default`), einen Site-Key und einen
Cache-Origin; die Kundenachse ist eine Zeile in `card_page`, keine Site.

Damit sind die Kundendomains auch keine Origins dieser API. Von einer
Kartenseite ruft kein Browser sie auf — die ganze Karte wird serverseitig
gerendert —, also gibt es keinen CORS-Eintrag und keine zweite Kopplung je
Domain.

## Bilder

Portrait und Logo liegen als Bytes in `card_asset` (`MEDIUMBLOB`), wie
`cms_legal_doc` und `app_user_avatar`. Ein Blob braucht kein neues schreibbares
Verzeichnis auf dem Plesk-Host, und Host-Einrichtung ist der chronische
Go-live-Blocker dieser Plattform.

PNG, JPEG und WebP, höchstens 2 MB. **Kein SVG** — das ist ein Dokument und kann
ein Skript tragen, und diese Bytes werden von derselben Domain ausgeliefert wie
das Sitzungs-Cookie. Der angegebene Medientyp wird ignoriert;
`getimagesizefromstring()` liest die Bytes, und das ist die einzige Behauptung,
der zu glauben ist. Serverseitig wird nicht skaliert (`ext-gd` ist auf dem Host
nicht garantiert) — das Panel verkleinert vorher im Browser.

## Berechtigungen

`cards:read` und `cards:write`. Admins passieren beide.

## Entwickeln

```bash
npm install --no-package-lock
npm run test:run        # 53 Tests: Manifest, Packaging, Oberfläche
npm run type-check
npm run build           # tsup → dist/

php ../.local-stack/tools/composer.phar install
php vendor/bin/phpunit  # 72 Tests: Migrationen, API-Doku, Domain, Bilder, Blöcke
```

Die Version steht in `package.json` und `composer.json` und wird **vom
Release-Workflow** gesetzt. Nicht von Hand hochzählen.

## Registrieren

Eine Erweiterung, die nirgends eingetragen ist, ist grün und trotzdem nicht
ausgeliefert. Sieben Stellen:

1. `tds-core-frontend-api/composer.json` — `require`
2. `tds-core-frontend-api/src/Modules.php` — `new CardsModule()`
3. `tds-gateway-api/.github/workflows/_assemble.yml` — Checkout-Schritt
4. ebendort der SHA-Block — `"cards": "$(sha _src/tds-ext-cards-pkg)"`
5. `tds-admin-frontend/package.json` — Abhängigkeit
6. `tds-admin-frontend/astro.config.mjs` — `frontendHost({ extensions: [...] })`
7. `tds-core-frontend-api/src/Service/SiteKeyPolicy.php` — `KNOWN`, im Gleichschritt
   mit `tds-shared/src/install/profiles.ts`

Details und die Gründe stehen in `AGENTS.md`.
