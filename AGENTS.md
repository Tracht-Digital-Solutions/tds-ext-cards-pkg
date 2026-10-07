# AGENTS.md — tds-ext-cards-pkg

Frontend extension for **Visitenkarten-Seiten**: one link page per customer, created in the
panel and served by `tds-card-frontend` on the customer's own domain. This package owns the
data, the API and the panel screen. It is **not** the renderer. The block model both halves
obey lives in `tds-shared` (`schemas/cardBlocks`).

Read `tds-frontend-contract-pkg/AGENTS.md` and `tds-core-frontend-api/AGENTS.md` first.

## Commands

```bash
npm install --no-package-lock   # never npm ci
npm run type-check
npm run lint:primitives
npm run test:run                # vitest
npm run build                   # tsup
composer install && composer test   # phpunit
```

## Hard rules

- `Support/CardDomain::normalize()` has a twin in `tds-card-frontend/src/lib/host.ts`. Change one, change both.
- `siteKeyRoutes()` lists both `/content/card` and `/content/cards`.
- Public reads filter drafts **in SQL**, never in PHP.
- `published_at` comes from the database clock, never `date()` / `gmdate()`.
- Migrations: band `20260929`, file name maps to class, unsigned `*_id`, no adapter internals, never delete a migration.
- Call the API with `apiFetch`. Never mount a `ToastHost`. No CSS.
- Stay in the `0.1.x` line (the host pins `^0.1.x`). Versions move via the release workflow only.

## Topic files

| File | Read before |
|---|---|
| [docs/agents/architecture.md](docs/agents/architecture.md) | Changing routes, domains, publishing, images or the panel screen |
| [docs/agents/backend.md](docs/agents/backend.md) | Touching PHP, migrations or `php/docs/api.php` |
| [docs/agents/frontend-conventions.md](docs/agents/frontend-conventions.md) | Touching any island, page or widget markup |
| [docs/agents/testing.md](docs/agents/testing.md) | Writing or changing tests |
| [docs/agents/release.md](docs/agents/release.md) | Releasing or changing CI |

Workspace rules: `../CLAUDE.md`. Cross-repo state: `../MIGRATION-STATUS.md`.
