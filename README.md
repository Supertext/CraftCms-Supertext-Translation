# Supertext Translation for Craft CMS

Translate Craft CMS entries into your other sites with **Supertext AI**, right on the entry page.

Open an entry, tick the sites to translate into and click **Translate**: the title, slug, text and rich-text fields and the content of Matrix fields go to Supertext in one request per language and come back as that site's version of the entry, saved as a new revision. Editors review and adjust the translation in Craft as usual.

- Uses Craft's own multi-site: one site per language, only translatable fields are translated
- Rich text (CKEditor) keeps its headings, bold text, links and lists; whole sentences are translated
- Translated slugs, so translated pages get translated URLs
- Sites with their own text are kept unless the editor explicitly overwrites them
- Bulk translation from the entry index (in the queue)
- Formal or informal tone and custom Supertext language codes per site; *Test connection* in the settings
- Craft permissions apply; console command for scripts

![The Translate with Supertext box on an entry](docs/images/01-translate-box.png)

## Documentation

| Guide | For |
| --- | --- |
| [Installation guide](docs/INSTALLATION.md) | Administrators: requirements, install, API key, sites and languages, permissions, settings, troubleshooting |
| [User guide](docs/USER_GUIDE.md) | Editors: translating, reviewing, overwriting, bulk translation, what gets translated |
| [Developer guide](docs/DEVELOPER.md) | Architecture, API protocol, local development, tests, demo deployment, releases |

Quick start:

```bash
composer config repositories.supertext vcs https://github.com/Supertext/CraftCms-Supertext-Translation
composer require supertext/craft-supertext-translation:dev-main
php craft plugin/install supertext-translation
# .env: SUPERTEXT_API_KEY="…"
```

## Demo

`demo/` is a Craft CMS 5 site with an English sample article and German, French and Italian (Switzerland) sites, deployed to Railway from this repository. See the [developer guide](docs/DEVELOPER.md#demo-railway).

Part of Supertext's translation plugins for open source CMSs, alongside the plugins for [WordPress](https://github.com/Supertext/supertext-wordpress-polylang), [Drupal](https://www.drupal.org/project/tmgmt_supertext_ai), [Joomla](https://github.com/Supertext/Joomla-Supertext-Translation), [TYPO3](https://github.com/Supertext/TYPO3-Supertext-Translation) and others.

## Changelog and roadmap

See [CHANGELOG.md](CHANGELOG.md) and the [roadmap](docs/DEVELOPER.md#known-limitations--roadmap).

## License

MIT. © Supertext AG
