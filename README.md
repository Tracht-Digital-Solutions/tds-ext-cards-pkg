# tds-ext-template-pkg

Template for a **TDS frontend extension**. Clone it, run the rename checklist, and
you have a working extension — frontend manifest, PHP `Module`, and the dual
CI/publish pipeline all wired against `tds-frontend-contract-pkg`.

## What you get

- **Frontend** (`src/index.ts`) — a `defineExtension({...})` manifest with all
  six contribution slots (permissions, nav, widgets, routes, settings, i18n) and
  the `.astro` pages / widgets / settings + a hydrated React island.
- **Backend** (`php/src/TemplateModule.php`) — a `Module` mounting a route, a
  permission, and a prefixed Phinx migration, plus a phpunit test.
- **Pipeline** (`.github/workflows/`) — `ci` (PR gate: phpunit + npm build),
  `dev` (prerelease `@dev` on push), `release` (manual `@latest` + tag). Inline,
  because org policy blocks cross-repo reusable workflows.

## Create a new extension

1. **Create the repo from this template** (GitHub “Use this template”, or clone +
   re-init git + new remote). Make it **public** (public GitHub Packages are free).
2. **Add the `PACKAGE_TOKEN` secret** (classic PAT: `read`+`write`+`delete:packages`
   + `repo`, SSO-authorized for the org).
3. **Rename** — replace throughout:
   - `template` → your extension id (kebab-case) in `src/index.ts`, and the
     contribution ids/labels
   - `tds-ext-template-pkg` → `tds-ext-<yours>` in `package.json` (name + repository),
     `composer.json` (name), the `island`/`entrypoint` specifiers in `src/index.ts`,
     `tsup.config.ts` external, and the workflow `package-name:` (×2)
   - `Tds\Ext\Template` → your PHP namespace (`composer.json` autoload +
     `php/src`, `php/tests`)
   - `Template` migration class prefix → your module id prefix
   - **the migration's version prefix** → a fresh date. Every enabled extension
     shares ONE `phinxlog`, so a reused numeric prefix collides there and Phinx
     aborts — and an aborted scan means **no** extension migrates, not just
     yours. Rename the file and the class together (Phinx derives the expected
     class from the file name). Bands already in use: `202607*`, `202608*`.
4. **Implement** your slots; delete the ones you don't use.
5. **Enable it** in the products: add the manifest to the host's
   `astro.config.mjs` (`frontendHost({ extensions: [...] })`) and `new YourModule()`
   to `tds-core-frontend-api`'s `Modules::enabled()`.

## Develop

```bash
npm install        # pulls tds-frontend-contract from GitHub Packages (needs NPM_TOKEN)
npm run build && npm run type-check
composer install   # resolves tds-frontend-contract from its public VCS repo
composer test
```

The manifest's `island`/`entrypoint` values are package subpaths (see `exports`)
the host's Astro/Vite resolves — not local files.

## Versioning

Semver, bump `package.json` **and** `composer.json` in lockstep (the release
workflow does this automatically). npm → GitHub Packages; the Composer half is
consumed via the git tag the release pushes.
