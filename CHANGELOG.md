# Release Notes for Supertext Translation

## Unreleased

### Added
- First version for Craft CMS 5.
- *Translate with Supertext* box on the entry edit page: translate the current site's version into the entry's other sites, with "Already translated" / "Translated with Supertext on …" per site and an explicit *Overwrite existing translations* option.
- Translates titles, slugs (as words, then slugified), Plain Text, CKEditor and Redactor fields and the nested entries of Matrix fields; only fields that are translatable in Craft.
- Saves each translation as a new revision ("Translated with Supertext from …").
- Entry index action *Translate with Supertext* (queue; sites without their own text only).
- Permission *Translate entries with Supertext*; the editor's site and section permissions are checked.
- Settings: API key (environment variable reference, with or without the `Supertext-Auth-Key` prefix; the field links to the Supertext signup and the API key page, *supertext.com → Integrations → API*, Admin role required), Live/Staging/Testing API or a custom address, Supertext language and form of address per site, timeout, *Test connection*.
- Console commands `supertext-translation/translate` and `supertext-translation/translate/check`.
- Retries when the Supertext API answers HTTP 429 (rate limit).
- English and German control panel texts.
- Demo for Railway (`demo/`) with demo accounts, four sites and a sample article created on every start.
