# Developer guide — Supertext Translation for Craft CMS

How the plugin is built, how to work on it, and how it is released and deployed.

## Architecture

A Craft 5 plugin (`supertext/craft-supertext-translation`, handle `supertext-translation`, namespace `supertext\crafttranslation`).

```
Entry edit page: "Translate with Supertext" box (sidebar)          web/assets/translate/dist/translate.js
   │ GET  actions/supertext-translation/translate/info?entryId=&siteId=
   │ POST actions/supertext-translation/translate/entry  { entryId, sourceSiteId, targetSiteIds[], overwrite }
   ▼
controllers/TranslateController ── requires the plugin permission, the editor's own user
   ▼
services/Translator::translate()
   │ collectUnits(): title, slug, Plain Text / CKEditor / Redactor fields, nested Matrix entries (recursive)
   │ per target site: skip if it has its own text (unless overwrite), check canSave()
   │ api/HtmlDocument::build(): one <div data-st-id="N"> per value; chunks below 900k characters
   ▼
api/SupertextClient   POST file → poll status → GET translation → DELETE   (Guzzle via Craft::createGuzzleClient)
   ▼
apply(): set the values on the entry (and nested entries) in the target site, save nested entries,
         then the entry (revision "Translated with Supertext from …"); remember() in supertext_translations
```

| Path | Responsibility |
| --- | --- |
| `src/Plugin.php` | Registers the permission `supertext-translation:translate`, the entry index action, the sidebar box (`Element::EVENT_DEFINE_SIDEBAR_HTML`), settings page, console controllers |
| `src/api/` | Supertext API client, HTML document, exception. No Craft dependencies (HTTP through a transport callable), unit-tested on their own |
| `src/services/Translator.php` | Field rules, "already translated" check, translation, saving |
| `src/controllers/TranslateController.php` | Control panel actions `info`, `entry`, `test-connection` (admins) |
| `src/elements/actions/TranslateWithSupertext.php`, `src/jobs/TranslateEntryJob.php` | Bulk translation in the queue (missing sites only) |
| `src/console/controllers/TranslateController.php` | `php craft supertext-translation/translate <entryId> [--from=en] [--to=de,fr] [--overwrite]`, `php craft supertext-translation/translate/check` |
| `src/models/Settings.php`, `src/templates/_settings.twig` | Settings (project config; API key as `$SUPERTEXT_API_KEY`) |
| `src/records/TranslationRecord.php`, `src/migrations/Install.php` | Table `supertext_translations`: last Supertext translation per entry and site |
| `src/translations/{de,fr,it}/supertext-translation.php` | German, French and Italian strings (keys are the English source strings) |
| `src/helpers/Messages.php` | Shows a `SupertextException` from the API client in the user's language (by its `reason`), falling back to the client's English message |

### Field rules

`Translator::collectUnits()` and `kindOf()`:

- **Title**: if the entry type has a title field and its translation method isn't *none*.
- **Slug** (top-level entry only): if it looks like words joined by hyphens; sent as words (`a-b-c` → `a b c`), turned back into a slug with `ElementHelper::generateSlug()` in the target site's language (so `limitAutoSlugsToAscii` applies).
- **Plain Text** → escaped text, line breaks as `<br>`. **CKEditor / Redactor** (`craft\ckeditor\Field`, `craft\redactor\Field`) → HTML as is.
- Only fields where `$field->getIsTranslatable($entry)` is true.
- **Matrix** → recursively the nested entries' titles and fields (up to 5 levels). The nested entry with the same ID in the target site gets the translation, so this needs Matrix propagation that keeps nested entries in all sites (the default).
- Empty values are skipped; an empty translation never overwrites.

Each value is one `data-st-id` element, so a whole rich-text field (all its paragraphs, bold words and links) is translated in context.

**"Already translated"**: a target site has its own text when any collected value (except the slug) differs from the source site's value. Without *overwrite* such sites are skipped. In Craft a site's version starts as a copy of the source, so "same as the source" means "not translated yet".

**Saving**: values are set on the target site's elements and saved with `Elements::saveElement()` (nested entries first). Saving the entry creates a revision with notes; the site's enabled status is not changed.

## Supertext API protocol

Shared with the WordPress plugin and every other Supertext CMS plugin:

1. `POST {base}translate/ai/file`: multipart with `file` (part `Content-Type` exactly `text/html`, no charset, or the API answers 415), `target_lang` (BCP-47, e.g. `de-CH`), optional `source_lang` (primary subtag only, e.g. `en`, or the pair is rejected), optional `politeness` (`more`/`less`). Returns `{file_id}`.
2. `GET …/{file_id}/status` until `done` (`error`, `limit_exceeded`, `deleted` are terminal).
3. `GET …/{file_id}/translation` returns the translated HTML.
4. `DELETE …/{file_id}` (files also expire after 24 h).

Auth header: `Authorization: Supertext-Auth-Key <key>`. The header name must be `Authorization` (`Authentication` gets 403). Supertext shows the key with the prefix, so the client strips a pasted `Supertext-Auth-Key ` and always sends exactly one. Base URLs: `https://api.supertext.com/v1/` (live), `https://api.staging.supertext.com/v1/`, `https://api.testing.supertext.com/v1/`. `GET features` is the cost-free key check (*Test connection*, `translate/check`).

**Rate limit:** the API limits requests per second per key (HTTP 429). The client retries a 429 up to 4 times, waiting for `Retry-After` if sent, otherwise 1, 2, 4 and 8 seconds plus jitter. Target sites are translated one after the other.

## Local development

Use the demo project in `demo/project` (it requires the plugin from the repository root through a Composer path repository):

```bash
cd demo/project
composer install
# .env: CRAFT_DB_DRIVER=pgsql, CRAFT_DB_SERVER, CRAFT_DB_PORT, CRAFT_DB_DATABASE, CRAFT_DB_USER,
#       CRAFT_DB_PASSWORD, CRAFT_SECURITY_KEY, PRIMARY_SITE_URL=http://127.0.0.1:8090/, SUPERTEXT_API_KEY
php craft install/craft --email=you@example.com --username=you@example.com --password=… \
  --site-name="Supertext Craft Demo" --site-url='$PRIMARY_SITE_URL' --language=en-US
php craft plugin/install ckeditor && php craft plugin/install supertext-translation
DEMO_EDITOR_EMAIL=editor@example.com DEMO_EDITOR_PASSWORD=… php craft supertext-demo/setup
php craft serve 127.0.0.1:8090        # control panel: http://127.0.0.1:8090/admin
```

To work without a real key, run the stand-in API (`node tests/docs/stand-in.mjs`) and set `SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/`. It returns real German, French and Italian for the sample article and `[de-CH] …`-prefixed text for anything else.

## Tests

```bash
composer install
vendor/bin/phpunit
```

- `tests/unit/SupertextClientTest.php`: the API protocol, auth header and prefix, 429 retries, errors, clean-up.
- `tests/unit/HtmlDocumentTest.php`: document packing and parsing, whitespace.
- `tests/unit/ChunksTest.php`: splitting documents below the size limit.
- `tests/unit/TranslationsTest.php`: every string passed to `Craft::t('supertext-translation', …)`, `|t('supertext-translation')` or listed in `TranslateAsset::MESSAGES` is in the German, French and Italian files (and nothing else is), with the same placeholders, tags and URLs; every `t()` in the edit-page JavaScript is in `TranslateAsset::MESSAGES`.
- `tests/demo-check.sh` (CI): the demo image on PostgreSQL with the stand-in, started twice: demo accounts created once and never duplicated, no passwords in the log, the Editors group, `translate/check`, translation of the sample article into three sites (title, ASCII slug, rich text markup, nested Matrix entries), the skip on a second run, and the German front-end page.

CI (`.github/workflows/ci.yml`) on every push and pull request: **test** (PHP 8.2, 8.3 and 8.4: lint and PHPUnit) and **demo** (builds `demo/Dockerfile`, runs `tests/demo-check.sh`).

## Demo (Railway)

The public demo is a container built from `demo/Dockerfile`: PHP 8.3 with Apache, Craft CMS 5.11 Pro with CKEditor and this plugin, sites English (`en-US`, primary), Deutsch (`de-CH`), Français (`fr-CH`) and Italiano (`it-CH`), and a sample article. It runs on Railway in the `supertext-cms-demos` project, service `CraftCMS`, region EU West (Amsterdam): <https://craftcms-production-4aa0.up.railway.app/> (control panel: `/admin`). Data lives in a `craft` database on the project's PostgreSQL service.

**Deploys:** Railway builds `main` of this repository (`railway.json` points it at `demo/Dockerfile`). If a push doesn't start a deployment, check that Railway's GitHub app has access to the repository, or redeploy the service (a redeploy also picks up the latest commit).

**What's in `demo/`:**

| Path | Purpose |
| --- | --- |
| `Dockerfile` | PHP 8.3 + Apache (document root `demo/project/web`), PHP extensions, Composer install of `demo/project` |
| `docker/entrypoint.sh` | Every start: database (via `prepare-db.php`), install on the first start, `craft up`, plugins, `supertext-demo/setup`, Apache on `$PORT` |
| `docker/prepare-db.php` | Turns `DATABASE_URL` into `CRAFT_DB_*` for the database `CRAFT_DEMO_DB_NAME` (default `craft`) and creates it if missing |
| `project/` | The Craft project: `composer.json`/`.lock`, `config/` (Pro edition is set by the setup; ASCII slugs), front-end `templates/` with a language switcher, and `modules/supertextdemo` with the `supertext-demo/setup` console command |
| `.env.example` | The variables below |

**Demo setup** (`php craft supertext-demo/setup`, every start, only adds what is missing): Craft Pro (user groups), the four sites, fields *Summary* (Plain Text), *Body* (CKEditor with lists), *Highlights* (Matrix with *Highlight* entries: title and text), section *Articles* (all sites), the sample article, the plugin settings (key `$SUPERTEXT_API_KEY`, URL `$SUPERTEXT_API_URL`, formal tone for German, French and Italian), the user group **Editors** and the demo accounts.

**No volume:** Railway's volume limit for the project is reached, and the demo needs none: content and project config are in PostgreSQL (Craft writes `config/project/*.yaml` from the database on start). Uploaded files would disappear with the next deploy.

**Service variables:**

| Variable | |
| --- | --- |
| `DATABASE_URL` | `${{Postgres.DATABASE_URL}}`; the demo database (`CRAFT_DEMO_DB_NAME`, default `craft`) is created on that server if missing |
| `CRAFT_SECURITY_KEY` | Random secret |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD` | Administrator (the e-mail address is also the username) |
| `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | Editor for automated tests and screenshots: member of **Editors** (control panel, every site, articles incl. other authors' entries, *Translate entries with Supertext*). Craft has no built-in editor role, so the demo creates this group. |
| `SUPERTEXT_API_KEY` | Supertext key (the plugin setting references it) |
| `SUPERTEXT_API_URL` | Optional, e.g. a stand-in API |
| `PORT` | Port Apache listens on (Railway sets it) |

Railway also sets `RAILWAY_PUBLIC_DOMAIN`; the entrypoint makes `PRIMARY_SITE_URL` from it (the sites are `/`, `/de/`, `/fr/`, `/it/`).

**Demo accounts:** on every start the setup creates the `DEMO_ADMIN` and `DEMO_EDITOR` accounts if no account with that e-mail address or username exists. Existing accounts are never changed; change passwords in the control panel. Craft requires a valid e-mail address and a password of at least 6 characters; otherwise the account is skipped with a warning naming the variable and Craft's reason, and the demo still starts. Passwords are never logged.

**No installer screen:** Craft's web installer only appears on an empty database. The entrypoint installs Craft on the first start with `DEMO_ADMIN_*` as the first admin, or, if those aren't set (or Craft rejects them), with a throwaway `installer-…@example.com` admin whose random password is never stored or shown. The setup deletes that throwaway account as soon as a real active admin exists.

**Craft Pro:** the demo runs Craft Pro (user groups for the editor). Craft shows a licensing notice for Pro on a public domain without a license.

**Run it locally:**

```bash
docker build -f demo/Dockerfile -t supertext-craft-demo .
docker run --rm -p 8080:8080 \
  -e DATABASE_URL=postgresql://user:pass@host.docker.internal:5432/postgres -e CRAFT_SECURITY_KEY=dev \
  -e PRIMARY_SITE_URL=http://localhost:8080/ \
  -e DEMO_ADMIN_EMAIL=you@example.com -e DEMO_ADMIN_PASSWORD='choose-one' \
  -e SUPERTEXT_API_KEY=… supertext-craft-demo
# http://localhost:8080/admin
```

## Docs screenshots

The images in `docs/images/` are generated by `tests/docs/screenshots.mjs` (Playwright) from a freshly set-up demo (no translations yet) whose plugin talks to `tests/docs/stand-in.mjs`. The stand-in returns German, French and Italian for the sample article (`samples.json`, real Supertext output). Regenerate them whenever a screen they show changes:

```bash
cd tests/docs && npm install && npx playwright install chromium
npm run stand-in &
# a fresh demo with SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/ and DEMO_* set, served on :8090
BASE_URL=http://127.0.0.1:8090 DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… \
  DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… npm run screenshots
```

The script shows the live API address instead of the stand-in's on the settings page, and the public demo's address (`SITE_URL`) instead of the local one under *Sites*.

## Releasing

Releases are published by `.github/workflows/release.yml` when the version is officially bumped; nobody tags or creates releases by hand.

1. Move the *Unreleased* entries in `CHANGELOG.md` under a new `## X.Y.Z — YYYY-MM-DD` section, and keep an empty *Unreleased* above it.
2. There is no version field to change: Composer takes the version from the Git tag the workflow creates.
3. Push to `main`. The workflow tags `vX.Y.Z` and creates the GitHub release with the CHANGELOG section as notes (0.x versions as pre-releases). A push that adds no new version does nothing, and a version that is already released is skipped. After fixing a failed run, start it again with *Run workflow* on the *Release* workflow.

Keep the CHANGELOG format: Craft's Plugin Store reads it.
Planned: submit the package to Packagist and the Craft Plugin Store.
## Conventions

- PSR-12, PHP 8.2, strict types in new code; keep `src/api/` free of Craft classes.
- User-visible strings through `Craft::t('supertext-translation', …)` (or `|t('supertext-translation')` in Twig) with German, French and Italian in `src/translations/{de,fr,it}/supertext-translation.php`; new or changed strings need all three in the same commit. Strings used by the JavaScript are listed in `TranslateAsset::MESSAGES`. Formal address (Sie, vous, Lei) and Craft's own terms (*Eintrag/Website*, *entrée/site*, *articolo/sito*).
- The API client (`src/api/`, no Craft classes) throws `SupertextException` with an English message and a `reason` (e.g. `limit_exceeded`); `helpers/Messages::of()` maps the reason to a translated string. A new reason needs a `match` arm there and its strings.
- Keep the three docs in `docs/` current with every change (see `CLAUDE.md`).

## Known limitations / roadmap

- Translation runs inside the editor's request (one site after the other, up to *Timeout* each); the index action uses the queue.
- Supported field types: Plain Text, CKEditor, Redactor, Matrix. Not yet: Table cells, Link field labels, SEO plugin fields, entries nested inside CKEditor fields, categories, global sets and assets (alt text).
- Matrix fields that keep separate nested entries per site ("Only save entries to the site they were created in") are not translated.
- Not in the Plugin Store / on Packagist yet.
- Human (professional) translation orders are not supported yet (the WordPress plugin has them).
