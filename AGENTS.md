# AGENTS.md — tds-ext-cards-pkg

Visitenkarten-Seiten: eine Linktree-Seite je Kunde, im Panel angelegt, von
`tds-card-frontend` auf der eigenen Domain des Kunden ausgeliefert. Read
`tds-frontend-contract-pkg`'s AGENTS.md first — extensions implement that
contract — and `tds-core-frontend-api`'s for the host this composes into.

**What this package is not: the renderer.** It owns the data, the API and the
panel screen. The public pages live in `tds-card-frontend`, and the block model
both halves obey lives in `tds-shared` (`schemas/cardBlocks`) — see README.md
for why it sits there and not here.

## The five things that are specific to this extension

1. **`Support/CardDomain::normalize()` has a twin in another repository.**
   `tds-card-frontend/src/lib/host.ts` reads a request's `Host` with exactly
   these rules: lower-case, strip `www.`, strip the port, strip a trailing dot,
   and *refuse* — never strip — a scheme or a path. This function decides which
   customer's card a visitor sees. If the two disagree by one dot, a card
   answers 404 on its own domain; a 404 is never cached, so it keeps answering
   404 while every deployment marker stays green. Change one, change both, and
   `CardDomainTest` is where the cases are written down.
2. **There is no site registry, and that is the design.** One app answers every
   customer domain and picks the card by `Host`, so there is exactly one
   connection (`cards`/`default`), one site key, one cache origin. The customer
   axis is a row in `card_page`. The customer domains are therefore NOT origins
   of this API — no browser on a card page calls it — so there is no CORS entry
   and no second pairing per domain.
3. **`siteKeyRoutes()` needs BOTH `/content/card` and `/content/cards`**, and it
   looks as if one would do. `SiteKeyMiddleware::matches` compares on segment
   boundaries (deliberately, so `/content/blogroll` is not covered by
   `/content/blog`), so the plural route would have been served unprotected
   while looking exactly like a route somebody chose to leave open.
   `CardsApiDocsTest` asserts both directions.
4. **The public reads filter drafts in SQL, never in PHP.** A card that is not
   published must not be in the result set at all; a filter applied after the
   fetch is one `if` away from serving a draft on a customer's domain, and the
   mistake would be invisible in review. The public shape hard-codes
   `draft => false` for the same reason.
5. **`published_at` comes from the DATABASE** (`CardRepository::now()`), not
   from `date()` and not from `gmdate()`. The production session runs in Berlin
   time, so the row's own `CURRENT_TIMESTAMP` columns are local while PHP's
   `gmdate` is UTC — a card would look published an hour or two in the future.

## Shape (identical to any extension)

- `src/index.ts` — the `defineExtension({...})` manifest.
- `pages/*.astro` / `widgets/*.astro` / `islands/*` — the route/widget/settings
  slots' entrypoints (package subpaths in `exports`).
- `php/src/*Module.php` — the backend `Module`.
- `php/db/migrations/*` — Phinx migrations, class names **prefixed with the
  module id** (in-process auto-migrator = one process = no name reuse) — and the
  **file name must map to the class** (`20260929000001_create_cards_page.php` ⇒
  `CreateCardsPage`), so the module name goes in both. A mismatch throws
  `Could not find class …` during the *scan* and aborts every extension's
  migrations, not just yours.
- `php/docs/api.php` — one entry per mounted route (summary, params, responses,
  required permission), returned by the Module's `apiDocs()`. The admin
  frontend's API reference joins it onto the introspected Slim routes by
  `"<METHOD> <pattern>"`, so the pattern must be **verbatim**, inline regex
  included. `php/tests/*ApiDocsTest.php` asserts the documented set and the
  registered set are the same set — **keep both files when cloning**; adding a
  route without describing it then fails your own suite instead of quietly
  leaving a blank row in the reference.
- `.github/workflows/*` — inline dual pipeline (phpunit + npm publish).

## Styling: use the shared primitives, never invent a class name

**An extension ships no CSS.** There is no stylesheet in this package and there
must not be one — every token and component comes from `tds-shared`, which the
product already installs (declared here as a **peer** dependency, the same
treatment astro and react get). The host renders this markup inside the `panel`
surface, so the geometry is already decided.

The scaffold's markup is the reference. Use exactly these:

| Slot | Class |
|---|---|
| page shell | `tds-page` + `tds-page__head` > `h1.tds-page__title` (+ `tds-page__lede`) |
| dashboard widget | `article.tds-widget` > `h3.tds-widget__title`, figure `tds-widget__metric` |
| settings slot | `div.tds-settings-section__body` |
| record list | `ul.tds-list` > `li.tds-list__row` |
| card / table / empty | `tds-card` · `tds-table` · `tds-empty` |
| button | `btn` + `btn-primary` / `-accent` / `-ghost` / `-danger` (**both** classes) |
| inline label | `chip` + `chip--{neutral,success,warning,danger,info,cat-*}` |
| block message | `tds-alert` (+ `--success` / `--warning` / `--danger`) |
| label + control | `tds-field-row` · toggle row `tds-toggle-row` |
| message thread | `tds-thread` > `tds-thread__item--own` / `--other` |
| loading | `<Spinner />` from `tds-shared/components` |
| destructive confirm | `<ConfirmDialog />` from `tds-shared/components` — **never `window.confirm()`** |

**Do not invent a bespoke BEM name for any of the above.** Every extension used to
carry its own (`page page--x`, `widget widget--x`, `settings-section--x`,
`widget__metric`, `danger`, `<p>Wird geladen …</p>`) and **none of them had a CSS
rule anywhere** — they were a contract of intent that nothing implemented, so
those regions rendered as raw unstyled HTML. Undoing that took a sweep across all
14 extensions.

Three traps, each of which shipped as a real bug:

- **Never interpolate a class name.** `` className={`chip chip--${status}`} ``
  fails twice over: Tailwind cannot statically extract it, and a value matching no
  variant renders an unstyled element. Map explicitly, with a fallback. When the
  value comes from the **database**, use `resolveChipVariant()` from
  `@tracht-digital-solutions/tds-shared/design` — it is guaranteed to return a
  class that exists. (`badge badge--${status}` shipped in two islands; `.badge`
  never existed at all.)
- **`.status-pill` is an inline label, not a banner.** For a block message use
  `.tds-alert`. A stretched `<p class="status-pill">` was the most common misuse
  in the platform, at 24 sites.
- **Call the API with `apiFetch`, NEVER a relative `fetch`.**

  ```tsx
  import { apiFetch } from "@tracht-digital-solutions/tds-shared/api";

  const api = apiFetch; // sends the session cookie, resolves the API base
  ```

  Every extension used to define its own
  `const api = (path, init) => fetch(path, { credentials: "include", ...init })`
  — with a **relative** path. In a product that resolves against the product's
  own static host, and its SPA fallback answers unknown paths with **200 +
  HTML**: `res.ok` is `true`, `res.json()` throws, and the usual
  `.catch(() => setRows([]))` renders a calm, permanent empty state. No error,
  no console warning. The contact inbox reported "Keine Anfragen." for months
  with the rows in the database. `apiFetch` resolves the base from
  `<meta name="tds-api-base">` (written by the frontend host) and also routes
  401s through the host's session backstop.

  A mocked-fetch test cannot catch a regression here — a relative path satisfies
  every behavioural assertion — so **assert the absolute host explicitly** in at
  least one test.
- **Report every mutation's outcome, and report it with a toast.**

  ```tsx
  import { toast } from "@tracht-digital-solutions/tds-shared/components";

  const res = await api("/thing", { method: "PUT", body });
  if (res.ok) toast.success("Gespeichert.");
  else toast.danger(`Speichern fehlgeschlagen (HTTP ${res.status}).`);
  ```

  Rules that come with it:
  - **Never `await` a mutation and drop the response.** That was the single most
    common defect across the extensions — a 403 looked exactly like success:
    the dialog closed, the draft cleared, the list reloaded, and the row was
    still there. Optimistic UI must also roll back on failure.
  - **Failure messages carry the HTTP status.** It is what separates "session
    expired" from "service down" in a bug report.
  - **Transient outcome → toast. Persistent state → in-flow `.tds-alert`.**
    Load failures, form validation and "X is not configured" hints stay in the
    flow — the first two name something to fix, the third names something an
    operator has to go and set. Anything the user must **read or copy** (a
    temporary password, a one-time link) never goes in a toast.
  - **Never mount a `ToastHost`.** The frontend host mounts the only one; a
    second would double every toast.
  - The banner that keeps only failures gets `.tds-alert--danger`; several
    extensions were rendering "Fehler: …" in the info hue.
- **A destructive action needs a `<ConfirmDialog>`, and it is controlled.** Park
  the target in state from the row button, and let the dialog perform the action;
  pass `busy` while the request is in flight so it cannot be double-submitted
  (blocking `window.confirm()` gave that away for free — a non-blocking dialog
  must do it explicitly). Auditing every `method: "DELETE"` against its gate
  found **only 3 of 10 destructive actions confirmed at all** — invoices,
  customers, blog posts, FAQ entries, docs and milestones each deleted on a
  single unguarded click. The missing gate, not the ugly native prompt, is the
  failure mode to watch for; grep `method: "DELETE"` when you add one.
- **A JSX comment cannot sit in an expression position** — not after `=> (`, not
  in a ternary branch, not in a `.map()` return. It is valid only as JSX
  *children*. Put the note above the `return`; otherwise the build fails with a
  bare `Expected ")"` pointing at the comment's own closing line. The same applies
  to multi-line `{/* … */}` in an `.astro` template body.

For a component's **internal** layout, reach for the generic primitives before
inventing anything: `.tds-stack` (+ `--tight` / `--loose`) for a vertical stack —
form bodies, detail panels, reply lists; `.tds-row` (+ `--between`) for a
wrapping horizontal row — header rows, filter bars, tab strips; `.tds-compose`
(+ `__actions`) for a reply box. Those three plus the existing `.tds-toolbar`
(action rows) and `.tds-marginalia`-style `.marginalia` (metadata and hint text)
absorbed 46 of the class names extensions had invented for exactly these shapes.

**~31 names across the platform legitimately stay bespoke** and are knowingly
unstyled — genuinely singular internals such as `cms-editor__blocks`,
`live-chat-settings__matrix`, `blog-editor__preview`, `api-wiki__routes`,
`time-tracker__timer`. If you add one, expect it to render on browser defaults
until someone gives it a rule; that is the accepted trade, not an oversight.
(`widget-slot__*` looks orphan but is styled by an inline `<style>` in the host's
dashboard page.)

## Conventions baked in (don't regress)

- Depends on the **published** `tds-frontend-contract` (`^0.2.0`), not a path link —
  npm from GitHub Packages (via `.npmrc` + `NPM_TOKEN`), Composer from the public
  VCS repo. No local path repo — Composer fatals on a missing path repo in CI, so
  extensions resolve the contract purely via VCS (a clone, not a sibling).
- CI installs with **`npm install --no-package-lock`** (win32 lockfile breaks the
  Linux runner) — never `npm ci` + a committed lockfile here.
- `PACKAGE_TOKEN` (a public-Packages-friendly PAT) both installs the contract and
  publishes this package; set `NPM_TOKEN` from it in CI.
- **The npm and Composer versions move independently** — bump `package.json` for
  a frontend-only change (markup, islands, styling) and `composer.json` only when
  the PHP `Module` actually changes. The pushed tag is the Composer release ref.
  Every extension in the platform has its npm version ahead of its Composer one
  for exactly this reason; an earlier revision of this file claimed they move "in
  lockstep", which no repo has ever done.
- Declares `tds-shared` as a **peer** dependency (`>=0.14.0`), like astro and
  react: the product installs it, and a second copy in the extension would mean
  two token sets. An extension that omits it still builds — the product's copy
  resolves — so the omission is invisible until someone installs the package
  standalone. Keep it declared.

## Migrations: this module owns the band `20260929`

Every enabled extension shares ONE `phinxlog` and is included into ONE PHP
process, so three mistakes abort the run for **every** module rather than just
this one: a reused class name (an uncatchable fatal redeclaration), a file name
that does not map to its class (it throws while the set is *scanned*), and a
reused version prefix.

`php/tests/CardsMigrationsTest.php` pins all three, and two more that have each
taken production down once:

- **`signed => false` on every integer `*_id`.** Production is MySQL 8; local
  and CI are often MariaDB, which silently corrects the signedness mismatch
  MySQL 8 rejects outright.
- **No adapter internals.** `quoteValue()` is protected on `PdoAdapter` and
  absent from the `TimedOutputAdapter` a migration actually receives. One such
  call in the website-CMS died on every run and blocked every migration queued
  behind it, across all modules.

A migration that has ever been in the tree is never deleted — empty its `up()`
instead, or Phinx's ledger and the files disagree.

## Tests
- **CI runs `test:run` since 2026-08-25 — before that, none of these suites
  ever ran on a runner.** `_build.yml` had type-check, lint:primitives and
  build. That included the `ApiDocSource` parity test, whose entire job is to
  fail when a route gains or loses documentation.
- **The suites used to run against a tds-shared a dozen minors old, and the
  first honest run cost 30 failures across the twelve shipping extensions.**
  This package declares tds-shared as a **peer** with a `>=0.19.0` floor, so a
  fresh install resolved 0.19.0 while every product build composes the current
  one. Three separate behaviours had moved underneath the tests, and each is
  worth knowing because a new suite will hit them again:
  - `apiFetch` consults the host-side runtime config (`/tds-runtime.json`)
    before it resolves a URL, so `fetch.mock.calls[0]` is that probe, not the
    endpoint. Call **`primeRuntimeConfig(null)`** in `beforeEach` — the panel
    products never ship that file (they render `<meta name="tds-api-base">`),
    so "absent" is also what happens in production.
  - `apiFetch` is **async**: the request leaves on a later microtask than the
    render. Reading `mock.calls` on the line after `render(...)` yields
    `undefined`; `await waitFor(() => expect(fetch).toHaveBeenCalled())` first.
  - A multipart upload now carries an **empty** `headers` object rather than
    `undefined`. Identical to the browser — the boundary is still the
    browser's to set — so assert "no content-type header", never
    "headers is undefined".


```bash
npm run test:run        # vitest, 53 tests
php vendor/bin/phpunit  # 72 tests
```

The suites target what can only fail far from here — in someone else's build,
or on a customer's domain.

**PHP**

- `CardsMigrationsTest` — the band and the three abort-everything rules; see
  *Migrations* above.
- `CardsApiDocsTest` — the documented route set and the mounted route set are
  the SAME set, in both directions, and every public route is covered by a
  site-key prefix while no admin route is. It copies `SiteKeyMiddleware`'s own
  boundary rule on purpose: a plain `str_starts_with` here would pass for a
  prefix the middleware does not honour, which is the exact mistake it exists
  to catch.
- `CardDomainTest` — the normalisation, case by case, because it has a twin in
  another repository (see point 1 at the top).
- `CardBlocksTest`, `CardImageTest` — pure functions, no PDO, so they run with
  no database. `CardImageTest` carries 1×1 images as hex rather than generating
  them: the suite must not depend on `ext-gd`, which is the very extension the
  production host does not guarantee.

**Frontend**

- `src/index.test.ts` + `tests/packaging.test.ts` — the manifest as a product
  build sees it: every specifier resolves, is exported through `exports`, and
  is inside the published `files` allow-list. A missing entry there is an
  ENOENT in a product release, and a `tds-tool-*` package has already shipped
  one.
- `islands/CardsList.test.tsx` — the absolute API host (a relative path
  satisfies every other assertion), spread-not-replace on save, a cache report
  reported as what it is, a multipart upload with no JSON content type, and a
  selection surviving a refresh.
- `islands/BlockList.test.tsx` — spread-not-replace per block, two added blocks
  being two objects, and reordering reachable by keyboard.

`tests/packaging.test.ts` pins the version to the **0.1.x** line: the host
caret-pins `^0.1.x`, so a minor bump here is invisible to it.

## Mobile layout

This package ships **no CSS**, so every layout decision is a shared class or a
Tailwind utility, and neither is checked by anything at runtime. Two rules:

- **A row of more than two things — or any row holding a full-width field —
  goes on `.tds-row`, `.tds-list__row` or `.tds-toolbar`.** All three wrap.
  A hand-rolled `flex` does not, and on a 375px screen the overflow is not
  even visible: `body { overflow-x: hidden }` clips it, so the content simply
  is not there.
- **A `<table>` needs `tds-table` and nothing else.** The primitive turns
  itself into a horizontal scroller below 40rem; an extra `overflow-x`
  wrapper or an inline style is redundant. A table with no focusable cell
  also needs `tabindex="0"` + `role="region"` + a label, or its scrollport
  cannot be reached by keyboard.

`npm run lint:primitives` enforces the class part of this (including a
`<table>` without `tds-table` and a flex/grid table cell, which silently
drops the cell out of the column algorithm). It is a **regex scan**, so a tag
name written inside a comment counts as markup — name elements in prose.

**`scripts/lint-primitives.mjs` here is the seed for all 20 repos that carry it**
(14 `tds-ext-*`, 4 `tds-tool-*`, `tds-core-frontend-pkg`, `tds-tools-frontend`)
and every copy is byte-identical. Reusable workflows are org-blocked, so copying
is the mechanism — change it here, then propagate to all 20 and re-run each one.

It also checks that a `btn-*` variant actually exists in tds-shared (`btn
btn-secondary` used to pass while matching no rule at all — geometry and a touch
target, no colour) and accepts `.tds-dropdown__trigger`/`__item` as shared
classes, because forcing `.btn` onto a menu row would give it pill radius and
button padding. Those two checks lived only in `tds-core-frontend-pkg` until
2026-08-16; they are merged in, so don't fork the script again.

> **Fixed 2026-08-16 — read this before "fixing" a false positive.** Tags used to
> be matched with `[^>]*>`, which stops at the **first `>`**, and an arrow handler
> (`onClick={() => …}`) supplies one. A correctly classed control written after its
> handler was therefore reported as bare, and every repo had absorbed that by
> putting `className` first — a convention nobody chose, enforced by a bug. It also
> under-reported in silence: the `<td>`/`<th>` rule looks *for* a class, so a
> truncated tag meant a `flex` cell was never found at all. `readTag()` now walks
> the tag tracking string state and `{}` depth. `classOf()` additionally resolves a
> local `const field = "…"`, so the check no longer depends on what a variable is
> *named* (`{field}` passed, `{area}` did not). All 20 repos were re-run after the
> fix with **zero findings**, so nothing had been hiding behind it.
