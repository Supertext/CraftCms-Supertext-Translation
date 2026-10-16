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
   ▼
api/SupertextClient::translateSegments()   the bundled Supertext SDK: one <div data-st-id="N"> per value,
   │   documents below 900k characters, POST file → poll status → GET translation → DELETE
   │   (HTTP through Craft::createGuzzleClient via api/CallableTransport)
   ▼
apply(): set the values on the entry (and nested entries) in the target site, save nested entries,
         then the entry (revision "Translated with Supertext from …"); remember() in supertext_translations
```

| Path | Responsibility |
| --- | --- |
| `src/Plugin.php` | Registers the permission `supertext-translation:translate`, the entry index action, the sidebar box (`Element::EVENT_DEFINE_SIDEBAR_HTML`), settings page, console controllers |
| `src/api/` | Wrapper around the Supertext SDK (`SupertextClient`, `CallableTransport`), language codes (`Languages`), Commando Center glue (`Reporting`, `ReportTransport`), exception. No Craft dependencies (HTTP through a transport callable), unit-tested on their own |
| `lib/supertext-sdk/` | Bundled copy of the Supertext PHP SDK, namespace `supertext\crafttranslation\sdk` (see *Supertext SDK*) |
| `lib/supertext-plugin-health/` | Bundled copy of Supertext Plugin Health (PHP), namespace `supertext\crafttranslation\pluginhealth` (see *Supertext Plugin Health*) |
| `src/services/Translator.php` | Field rules, "already translated" check, translation, saving |
| `src/controllers/TranslateController.php` | Control panel actions `info`, `entry`, `test-connection` (admins: the API key check) |
| `src/controllers/PingController.php` | Public `actions/supertext-translation/ping` for the Commando Center |
| `src/services/Reporting.php` | Commando Center on Craft: site URL, versions, when the reporter runs, its state in the table `supertext_state` |
| `src/elements/actions/TranslateWithSupertext.php`, `src/jobs/TranslateEntryJob.php` | Bulk translation in the queue (missing sites only) |
| `src/console/controllers/TranslateController.php` | `php craft supertext-translation/translate <entryId> [--from=en] [--to=de,fr] [--overwrite]`, `php craft supertext-translation/translate/check` |
| `src/models/Settings.php`, `src/templates/_settings.twig` | Settings (project config; API key as `$SUPERTEXT_API_KEY`) |
| `src/records/TranslationRecord.php`, `src/migrations/` | Table `supertext_translations` (last Supertext translation per entry and site) and `supertext_state` (`m261014_000000_supertext_state`: the reporter's state) |
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

1. `POST {base}translate/ai/file`: multipart with `file` (part `Content-Type` exactly `text/html`, no charset, or the API answers 415), `target_lang` (a Supertext target code, e.g. `de-CH` or `es`), optional `source_lang` (bare language, e.g. `en`); see *Language codes*, optional `politeness` (`more`/`less`). Returns `{file_id}`.
2. `GET …/{file_id}/status` until `done` (`error`, `limit_exceeded`, `deleted` are terminal).
3. `GET …/{file_id}/translation` returns the translated HTML.
4. `DELETE …/{file_id}` (files also expire after 24 h).

Auth header: `Authorization: Supertext-Auth-Key <key>`. The header name must be `Authorization` (`Authentication` gets 403). Supertext shows the key with the prefix, so the client strips a pasted `Supertext-Auth-Key ` and always sends exactly one. Base URLs: `https://api.supertext.com/v1/` (live), `https://api.staging.supertext.com/v1/`, `https://api.testing.supertext.com/v1/`. `GET features` is the cost-free key check (*Test connection*, `translate/check`).

**Rate limit:** the API limits requests per second per key (HTTP 429). The client retries a 429 up to 4 times, waiting for `Retry-After` if sent, otherwise 1, 2, 4 and 8 seconds plus jitter. Target sites are translated one after the other.

## Supertext SDK

The protocol above, the retries, the language list, the API key check (`Client::checkApiKey()`, `Protocol::apiKeyFingerprint()`) and the `data-st-id` document come from the official Supertext PHP SDK ([Supertext/Supertext-API-SDK-and-Guide](https://github.com/Supertext/Supertext-API-SDK-and-Guide), 0.3.0): it provides the client and the key check. The version reports are no longer in the SDK (since 0.3.0) but in *Supertext Plugin Health* (below). The plugin ships a copy in `lib/supertext-sdk/` with its namespace changed from `Supertext\Sdk` to `supertext\crafttranslation\sdk` (everything that talks to the API is in its `V1` sub-namespace, `supertext\crafttranslation\sdk\V1`, folder `src/V1/`; Composer autoloads it from the plugin's `composer.json`), so sites install nothing extra and another package that requires the SDK itself can't clash with it. `lib/supertext-sdk/COMMIT` names the SDK revision.

- Never edit `lib/supertext-sdk/`. Fix the SDK in its repository, then run `scripts/vendor-sdk.sh [ref]` (default `main`; `SDK_DIR=/path/to/clone` uses a local clone) and the tests. The script copies `sdk/php/src` with its subfolders and rewrites every `Supertext\Sdk\…` namespace (`V1` and `Http` included).
- `src/api/SupertextClient.php` keeps the plugin's interface (`translateDocument`, `translateSegments`, `validateApiKey`, transport callable) and turns every SDK error code into a `SupertextException` reason (`REASONS`); `helpers/Messages::of()` translates it. A new SDK error code needs a reason there, a `match` arm in `Messages` and its strings in `de`, `fr` and `it`.
- The demo installs the plugin from `demo/project/composer.lock`, which records the plugin's autoload paths: after changing `autoload` in `composer.json`, run `composer update supertext/craft-supertext-translation --no-install` in `demo/project` and commit the lock (otherwise the demo can't load the SDK classes).
- PHPStan analyses `src/` only; the SDK has its own checks in its repository.
- Segments missing from Supertext's answer are left untouched in the target site (the SDK's `translateTexts()` would fail the whole entry instead).
- The live API answers an unknown language (`xx-YY`) with HTTP 404 `INVALID_LANGUAGE_PAIR`; the SDK reports it as `invalid_language_pair` with both codes, shown to editors as "Supertext does not support this language pair (en → xx-YY)".

## Language codes

Supertext accepts exactly the codes its `GET features` lists (the SDK's `Languages`, from the live API): bare sources (`en`, `de`); targets in one exact form each, regional for German, English, French, Italian and Portuguese (`de-CH`, `en-US`, `pt-BR`), with a script for Chinese and Serbian (`zh-Hans`, `sr-Latn`), a dialect for Swiss German (`gsw-u-sd-chzh`), bare for the others (`es`, `nl`, `sl`). Anything else is HTTP 404 `INVALID_LANGUAGE_PAIR`; formal/informal for a target without it (`en-US`, `rm`, `sq`, `sr-*`, `gsw-*`) is HTTP 400 `FEATURE_ERROR` (`unsupported_feature`).

`src/api/Languages.php` (the SDK's `Languages` plus the per-site setting; no Craft dependency, `tests/unit/LanguagesTest.php`) resolves a site, **without guessing**:

- **Target:** the site's *Supertext language* (`languages[<handle>][code]` in the settings, sent as it is, even a code the SDK's list doesn't know yet); else the site's language if it equals a Supertext code apart from case and `_`/`-` (`de-CH`, `fr_ch` → `fr-CH`, `es`); else `SupertextException` with reason `unsupported_language` (the site language as `{0}`), which the translator reports for that site without calling Supertext. No default regions: a site in `de` must be mapped.
- **Source:** the same setting as the SDK's mapping (`Languages::source($siteLanguage, [$siteLanguage => $code])`): the source form of the mapped code (`de-CH` → `de`) or of the site language (`en-US` → `en`), else `unsupported_language`.
- **Settings page:** the select lists `Languages::targets()` (name and code), pre-filled with `Languages::match()` (exact match) when the site has no code yet; a stored code not in the list is kept as an extra option. *Not mapped* marks sites without a target; a warning appears when *Form of address* is set for a target where `supportsPoliteness()` is false. The value is still sent (Supertext answers `unsupported_feature`, shown per site), so a setting is never dropped silently.

`SupertextClient` sends the codes it is given; the stand-in (`tests/docs/stand-in.mjs`) accepts exactly Supertext's list and answers anything else with 404 `INVALID_LANGUAGE_PAIR`, like the real API.

## Supertext Plugin Health

[Supertext Plugin Health](https://github.com/Supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health) (`plugin-health/php` in the Commando Center repository) sends the version reports to the Supertext Commando Center, reports the result of the SDK's API key check and builds the ping endpoint's answer (see *Commando Center* below). It has no dependencies and doesn't need the SDK: `src/api/Reporting.php` passes it the SDK's key check (`CallableKeyChecker`) and `Supertext::SDK_VERSION`. The plugin ships a copy in `lib/supertext-plugin-health/` with its namespace changed from `Supertext\PluginHealth` to `supertext\crafttranslation\pluginhealth`, autoloaded from `composer.json` like the SDK copy (and recorded in `demo/project/composer.lock`, see above). `COMMIT` names the revision, `VERSION` its version (0.1.0).

- Never edit `lib/supertext-plugin-health/`. Fix it in Commando-Center-Supertext-Translation/plugin-health, then run `scripts/vendor-plugin-health.sh <tag>` (e.g. `plugin-health-v0.1.0`; default `main`; `PLUGIN_HEALTH_DIR=/path/to/clone` uses a local clone) and the tests.
- PHPStan analyses `src/` only; Plugin Health has its own checks in its repository.

## Commando Center: version reports, key check, ping

Protocol: [Commando-Center-Supertext-Translation](https://github.com/Supertext/Commando-Center-Supertext-Translation) → `docs/DEVELOPER.md` → *Report protocol*. Supertext Plugin Health's `InstallationReporter`, `reportsEnabled()` and `pingResponse()` implement it, with the SDK's `checkApiKey()` as key checker (against the configured API, through the plugin's transport); `src/api/Reporting.php` (no Craft classes) loads the state, runs one reporter action and stores the new state, never throwing; `src/services/Reporting.php` gives it what Craft knows.

- **Setting:** `Settings::$versionReports` (*Send version information to Supertext*, on by default, lightswitch on the settings page). `SUPERTEXT_VERSION_REPORTS` (`0/false/off/no`, `1/true/on/yes`) on the server wins (`reportsEnabled()`); the page says so. Off: no reports, ping 404. `SUPERTEXT_REPORT_URL` sends reports elsewhere (CI, screenshots: the stand-in's `POST /report`).
- **Report fields:** `site_url` = the primary site's base URL (else the request's host; console without one: nothing is reported, the key check still runs); plugin `CraftCms-Supertext-Translation` with `Plugin::getVersion()` (Composer's version, i.e. the Git tag); system `Craft CMS` with `Craft::$app->getVersion()`; runtime PHP; `sdk_version` = the SDK's `Supertext::SDK_VERSION`; `callback.url` = `<site>/actions/supertext-translation/ping` (`<site>/index.php?p=actions/…` without `omitScriptNameInUrls`). HTTP goes through the plugin's Guzzle transport (`CallableTransport` for the SDK, `ReportTransport` for Plugin Health), with their timeouts (3 s per report, 10 s for the key check).
- **When:**
  - `afterSettingsSaved`: `Plugin::afterSaveSettings()`, which Craft calls from `Plugins::savePluginSettings()` (every save in the control panel, and the demo setup's). The check is shown as a notice (ok) or error flash. Applying project config on another environment doesn't call it; the next request there runs `onRequest`.
  - `onRequest`: the settings page, `translate/info` (the Supertext box), the end of every `Translator::translate()` (control panel action, queue job, console command). On web requests it runs once, after the response was sent (`Response::EVENT_AFTER_SEND`, after `fastcgi_finish_request()` where available); on console and queue right away.
  - `authenticationFailed`: a translation failed with reason `auth_failed` (HTTP 401/403); replaces `onRequest` for that request.
  - `testConnection`: *Test connection* (`translate/test-connection`, returns `{ ok, status, message, checkedAt }`).
  - `translate/check` (console): `testConnection`, then `reportCheck` if that sent nothing.
- **State:** one row (`name = 'reporter'`, Plugin Health's JSON, the same the SDK 0.2 reporter stored, so the switch sent no new `installed` report) in `supertext_state`. Load and save errors (e.g. before `craft up` created the table) are swallowed: the state is just empty then. The API key is never stored or logged; only the SDK's fingerprint is kept.
- **Ping:** `controllers/PingController` (`$allowAnonymous = true`, no CSRF, GET only) sends `pingResponse()`'s status, headers and body as they are.
- **Messages:** `helpers/Messages::keyCheck()` has the center's five texts in four languages, with the account and API key links (new tab) for `invalid` and `missing`.

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

- `tests/unit/SupertextClientTest.php`: the SDK wrapper: protocol, codes sent as given, auth header and prefix, 429 retries, error reasons (language pair and missing tone included), the key check, clean-up, segments (plain text, HTML, blank, missing) and splitting into several documents.
- `tests/unit/LanguagesTest.php`: the per-site setting wins, exact matches, no default regions (`de`, `es-ES`, `zh-CN` → `unsupported_language`), sources from the same setting, the politeness list.
- `tests/unit/ReportingTest.php`: with a fake transport and in-memory storage: `installed`/`settings_saved` after a save (fields, 3 s timeout, never the key), nothing due on a plain request, `upgraded`, a 401/403 reported once, *Test connection*, the `check` report, nothing sent with reports off or without a site URL, the one-hour back-off, broken storage, ping answers, the environment variable.
- `tests/unit/TranslationsTest.php`: every string passed to `Craft::t('supertext-translation', …)`, `|t('supertext-translation')` or listed in `TranslateAsset::MESSAGES` is in the German, French and Italian files (and nothing else is), with the same placeholders, tags and URLs; every `t()` in the edit-page JavaScript is in `TranslateAsset::MESSAGES`.
- `tests/demo-check.sh` (CI): the demo image on PostgreSQL with the stand-in, started twice: demo accounts created once and never duplicated, no passwords in the log, the Editors group, `translate/check`, translation of the sample article into three sites (title, ASCII slug, rich text markup, nested Matrix entries), the skip on a second run, the German front-end page, and the Commando Center: the public ping (200 and 400) and the `installed` and `check` reports received by the stand-in (`SUPERTEXT_REPORT_URL`), with the plugin, Craft version and callback URL and never the key.

CI (`.github/workflows/ci.yml`) on every push and pull request: **test** (PHP 8.2, 8.3 and 8.4: lint and PHPUnit), **phpstan** (see *Code quality and security checks*) and **demo** (builds `demo/Dockerfile`, runs `tests/demo-check.sh`).

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

**Demo setup** (`php craft supertext-demo/setup`, every start, only adds what is missing): Craft Pro (user groups), the four sites, fields *Summary* (Plain Text), *Body* (CKEditor with lists), *Highlights* (Matrix with *Highlight* entries: title and text), section *Articles* (all sites), the sample article, the plugin settings (key `$SUPERTEXT_API_KEY`, URL `$SUPERTEXT_API_URL`, formal tone for German, French and Italian; the sites' languages `en-US`, `de-CH`, `fr-CH`, `it-CH` are Supertext codes, so no Supertext language needs choosing), the user group **Editors** and the demo accounts.

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
| `SUPERTEXT_VERSION_REPORTS` | Optional; `0` turns the version reports to Supertext off (default on: the demo reports like any installation) |
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
npm run stand-in &   # also the Commando Center: start the demo with SUPERTEXT_REPORT_URL=http://127.0.0.1:8765/report
# a fresh demo with SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/ and DEMO_* set, served on :8090
BASE_URL=http://127.0.0.1:8090 DEMO_ADMIN_EMAIL=… DEMO_ADMIN_PASSWORD=… \
  DEMO_EDITOR_EMAIL=… DEMO_EDITOR_PASSWORD=… npm run screenshots
```

The script shows the live API address instead of the stand-in's on the settings page, and the public demo's address (`SITE_URL`) instead of the local one under *Sites*.

## Code quality and security checks

- **Checks** (`.github/workflows/checks.yml`): on every push and pull request, [actionlint](https://github.com/rhysd/actionlint) and [zizmor](https://docs.zizmor.sh) lint the workflows. On pull requests, dependency review fails a PR that adds a package with a known vulnerability (moderate or worse). Third-party actions are pinned to commit SHAs (Dependabot keeps them current); checkouts don't keep credentials, and workflows get `contents: read` unless a job needs more (the release job: `contents: write`).
- **Links** (`.github/workflows/links.yml`): [lychee](https://lychee.cli.rs) checks the links in all Markdown files weekly and whenever docs change on `main`. Broken links open or update the issue "Broken links in the docs" (a docs push that breaks links also fails). Links that can't work from CI go in `.lycheeignore` (one regex per line).
- **PHPStan** (job `phpstan` in `ci.yml`, config `phpstan.neon`): level 5 on the plugin's own code (`src/`), not the tests or `demo/`. [craftcms/phpstan](https://github.com/craftcms/phpstan) (a dev dependency) tells PHPStan about Craft and Yii. Locally: `composer install`, then `composer phpstan` (or `vendor/bin/phpstan analyse`). Existing findings that aren't simple to fix are listed in `phpstan-baseline.neon` (regenerate with `vendor/bin/phpstan analyse --generate-baseline` after fixing one). New code must not add findings.
- **GitHub settings** (set by Remy's setup script, not in the repo): secret scanning with push protection (a push containing a known token format is rejected; findings under *Security → Secret scanning*) and CodeQL default setup (findings under *Security → Code scanning* and as PR comments). CodeQL doesn't cover PHP, which is why this repo runs PHPStan.

Before starting work in this repo, look at its open findings: code scanning alerts, secret scanning alerts, Dependabot PRs and the "Broken links in the docs" issue.

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
- The API client (`src/api/`, no Craft classes, built on the bundled SDK) throws `SupertextException` with an English message and a `reason` (e.g. `limit_exceeded`); `helpers/Messages::of()` maps the reason to a translated string. A new reason needs a `match` arm there and its strings.
- Keep the three docs in `docs/` current with every change (see `CLAUDE.md`).

## Known limitations / roadmap

- Translation runs inside the editor's request (one site after the other, up to *Timeout* each); the index action uses the queue.
- Supported field types: Plain Text, CKEditor, Redactor, Matrix. Not yet: Table cells, Link field labels, SEO plugin fields, entries nested inside CKEditor fields, categories, global sets and assets (alt text).
- Matrix fields that keep separate nested entries per site ("Only save entries to the site they were created in") are not translated.
- Not in the Plugin Store / on Packagist yet.
- The language list is the SDK's (October 2026); a language Supertext adds later can be used before the SDK knows it only if the site already stores its code. A future version could read `GET features` live.
- Human (professional) translation orders are not supported yet (the WordPress plugin has them).
