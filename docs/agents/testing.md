# Testing

```bash
npm run test:run   # vitest; islands opt into jsdom per file via a @vitest-environment docblock
composer test      # phpunit
```

CI (`_build.yml`) runs both suites, plus type-check, `lint:primitives` and build, on
every run.

## Test environment against the current tds-shared

tds-shared is a **peer** dependency, so a fresh standalone install may resolve an old
version while every product build composes the current one. Keep these in mind:

- `apiFetch` first consults the host runtime config (`/tds-runtime.json`), so
  `fetch.mock.calls[0]` is that probe. Call **`primeRuntimeConfig(null)`** in
  `beforeEach`; panel products never ship that file, so "absent" matches production.
- `apiFetch` is **async**. `await waitFor(() => expect(fetch).toHaveBeenCalled())`
  before reading `mock.calls`.
- A multipart upload carries an **empty** `headers` object. Assert "no content-type
  header", never "headers is undefined".
- Error-path tests answer with a **populated** body and a non-OK status. Against an
  empty error body the ok-check is unobservable.
- At least one assertion per suite pins the **absolute API host** (see
  [frontend-conventions.md](frontend-conventions.md#api-calls-apifetch-never-a-relative-fetch)).

## Suites

The suites target what can only fail far from here: in someone else's build or on a customer's
domain.

| Suite | Covers |
|---|---|
| `php/tests/CardsMigrationsTest.php` | The band and the migration rules (see [backend.md](backend.md)) |
| `php/tests/CardsApiDocsTest.php` | Documented = mounted routes; every public route covered by a site-key prefix, no admin route covered. Copies `SiteKeyMiddleware`'s segment-boundary rule on purpose; a plain `str_starts_with` would pass for prefixes the middleware doesn't honour |
| `php/tests/CardDomainTest.php` | Host normalisation case by case (twin in `tds-card-frontend`) |
| `php/tests/CardBlocksTest.php`, `CardImageTest.php` | Pure functions, no PDO. Images are 1×1 hex fixtures, so the suite doesn't depend on `ext-gd` |
| `src/index.test.ts` + `tests/packaging.test.ts` | Every specifier resolves, is exported and is in `files` (a missing entry is an ENOENT in a product release); version in the `0.1.x` line |
| `islands/CardsList.test.tsx` | Absolute API host, spread-not-replace, cache report, multipart upload, selection survives refresh |
| `islands/BlockList.test.tsx` | Spread-not-replace per block, distinct added blocks, keyboard reordering |
