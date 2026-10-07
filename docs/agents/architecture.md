# Architecture

## Routes

| Route | Access | Purpose |
|---|---|---|
| `GET /content/card` | site key | Card for the requesting host |
| `GET /content/cards` | site key | Published cards |
| `GET /content/card/{slug:[a-z0-9-]+}/{kind:portrait\|logo}` | site key | Public image |
| `GET /cards/summary` | `cards:read` | Widget |
| `GET` / `DELETE /cards/connection`, `POST /cards/connection/pairing` | read: `cards:read`, changes: `cards:write` | Site connection and pairing |
| `GET` / `POST /cards` | `cards:read` / `cards:write` | List / create |
| `GET` / `PUT` / `DELETE /cards/{slug:[a-z0-9-]+}` | `cards:*` | Card detail |
| `GET` / `POST` / `DELETE /cards/{slug}/image/{kind:portrait\|logo}` | `cards:*` | Image management |
| `POST /cards/{slug:[a-z0-9-]+}/cache/rebuild` | `cards:write` | Refresh the card's page cache |

## The five rules specific to this extension

1. **`Support/CardDomain::normalize()` has a twin in another repository.**
   `tds-card-frontend/src/lib/host.ts` reads a request's `Host` with exactly these rules:
   lower-case, strip `www.`, strip the port, strip a trailing dot, and **refuse** (never strip) a
   scheme or a path. This decides which customer's card a visitor sees. If the two disagree by
   one dot, a card answers 404 on its own domain; a 404 is never cached, so it stays 404 while
   every deployment marker is green. Change one, change both; `CardDomainTest` lists the cases.
2. **There is no site registry, by design.** One app answers every customer domain and picks
   the card by `Host`, so there is exactly one connection (`cards`/`default`), one site key and
   one cache origin. The customer axis is a row in `card_page`. Customer domains are **not**
   origins of this API (no browser on a card page calls it), so there is no CORS entry and no
   per-domain pairing.
3. **`siteKeyRoutes()` needs both `/content/card` and `/content/cards`.**
   `SiteKeyMiddleware::matches` compares on segment boundaries (so `/content/blogroll` isn't
   covered by `/content/blog`); with only one, the plural route would be served unprotected.
   `CardsApiDocsTest` asserts both directions.
4. **Public reads filter drafts in SQL, never in PHP.** An unpublished card must not be in the
   result set at all; a filter after the fetch is one `if` away from serving a draft on a
   customer's domain. The public shape hard-codes `draft => false` for the same reason.
5. **`published_at` comes from the database** (`CardRepository::now()`). The production session
   runs in Berlin time, so `CURRENT_TIMESTAMP` columns are local while PHP's `gmdate` is UTC; a
   card would look published one or two hours in the future.

## Data model

Tables `card_page` and `card_asset`; migrations `CreateCardsPage`, `CreateCardsAsset` in the
`20260929` band.

## Support classes

| Class | Role |
|---|---|
| `Domain/CardRepository` | Rows and the DB clock |
| `Support/CardDomain` | Host normalisation (twin in `tds-card-frontend`) |
| `Support/CardBlocks` | Block validation against the shared block model |
| `Support/CardImage` | Image handling without depending on `ext-gd` |

## Panel islands

- `CardsList` — spread, don't replace, on save; reports a cache result as what it is; uploads
  as multipart without a JSON content type; keeps the selection across a refresh.
- `BlockList` — spread, don't replace, per block; two added blocks are two objects; reordering
  is keyboard-reachable.
- `CardsConnection` — site connection and pairing.
