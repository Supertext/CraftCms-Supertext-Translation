<?php

namespace modules\supertextdemo\console;

use Craft;
use craft\console\Controller;
use craft\elements\Entry;
use craft\elements\User;
use craft\enums\CmsEdition;
use craft\enums\PropagationMethod;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Matrix;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use craft\models\UserGroup;
use yii\console\ExitCode;

/**
 * Demo setup, run on every start (idempotent; only adds what is missing, never changes
 * existing accounts):
 *
 * - Craft Pro (user groups), sites English (en-US, primary), Deutsch (de-CH), Français (fr-CH), Italiano (it-CH)
 * - fields Summary (plain text), Body (CKEditor), Highlights (Matrix with "Highlight" entries)
 * - section "Articles" with one English sample article
 * - user group "Editors" (all sites, articles, Supertext) and the DEMO_ADMIN_* / DEMO_EDITOR_* accounts
 * - plugin settings (key from $SUPERTEXT_API_KEY, formal tone)
 */
class SetupController extends Controller
{
    private const SITES = [
        ['handle' => 'de', 'name' => 'Deutsch', 'language' => 'de-CH', 'path' => 'de'],
        ['handle' => 'fr', 'name' => 'Français', 'language' => 'fr-CH', 'path' => 'fr'],
        ['handle' => 'it', 'name' => 'Italiano', 'language' => 'it-CH', 'path' => 'it'],
    ];

    public function actionIndex(): int
    {
        if (Craft::$app->edition !== CmsEdition::Pro) {
            Craft::$app->setEdition(CmsEdition::Pro);
            $this->log('Craft Pro edition enabled (user groups).');
        }

        $sites = $this->sites();
        $fields = $this->fields();
        $section = $this->section($fields, $sites);
        $this->sampleEntry($section);
        $this->pluginSettings();
        $group = $this->editorsGroup($section, $sites);

        $this->account('DEMO_ADMIN', null);
        $this->account('DEMO_EDITOR', $group);
        $this->removeInstaller();

        return ExitCode::OK;
    }

    /** @return Site[] all sites */
    private function sites(): array
    {
        $service = Craft::$app->getSites();
        $primary = $service->getPrimarySite();
        if ($primary->handle !== 'en') {
            $primary->handle = 'en';
            $primary->setName('English');
            $primary->language = 'en-US';
            $service->saveSite($primary);
            $this->log('Primary site renamed to English (en-US).');
        }
        foreach (self::SITES as $config) {
            if ($service->getSiteByHandle($config['handle'], false)) {
                continue;
            }
            $site = new Site([
                'groupId' => $primary->groupId,
                'handle' => $config['handle'],
                'language' => $config['language'],
                'hasUrls' => true,
                'baseUrl' => '${PRIMARY_SITE_URL}' . $config['path'] . '/',
                'enabled' => true,
            ]);
            $site->setName($config['name']);
            if (!$service->saveSite($site)) {
                throw new \RuntimeException('Could not save site ' . $config['handle'] . ': ' . implode(' ', $site->getFirstErrors()));
            }
            $this->log("Site {$config['name']} ({$config['language']}) added.");
        }

        return $service->getAllSites(true);
    }

    /** @return array<string, \craft\base\FieldInterface> */
    private function fields(): array
    {
        $service = Craft::$app->getFields();
        $make = function (string $handle, callable $create) use ($service) {
            $field = $service->getFieldByHandle($handle);
            if (!$field) {
                $field = $create();
                if (!$service->saveField($field)) {
                    throw new \RuntimeException("Could not save field {$handle}: " . implode(' ', $field->getFirstErrors()));
                }
                $this->log("Field {$handle} added.");
            }

            return $field;
        };

        $summary = $make('summary', fn() => new PlainText(['name' => 'Summary', 'handle' => 'summary', 'multiline' => true, 'initialRows' => 3, 'translationMethod' => 'site']));
        $text = $make('text', fn() => new PlainText(['name' => 'Text', 'handle' => 'text', 'multiline' => true, 'initialRows' => 2, 'translationMethod' => 'site']));
        $body = $make('body', fn() => new \craft\ckeditor\Field(['name' => 'Body', 'handle' => 'body', 'translationMethod' => 'site', 'toolbar' => ['heading', '|', 'bold', 'italic', 'link', '|', 'bulletedList', 'numberedList']]));

        $entries = Craft::$app->getEntries();
        $highlightType = $entries->getEntryTypeByHandle('highlight');
        if (!$highlightType) {
            $highlightType = new EntryType(['name' => 'Highlight', 'handle' => 'highlight', 'icon' => 'star', 'titleTranslationMethod' => 'site']);
            $highlightType->setFieldLayout($this->layout([$text]));
            $this->save($entries->saveEntryType($highlightType), $highlightType, 'entry type highlight');
        }
        $highlights = $make('highlights', fn() => new Matrix([
            'name' => 'Highlights',
            'handle' => 'highlights',
            'entryTypes' => [$highlightType],
            'propagationMethod' => PropagationMethod::All,
            'viewMode' => Matrix::VIEW_MODE_BLOCKS,
            'createButtonLabel' => 'Add a highlight',
        ]));

        return ['summary' => $summary, 'body' => $body, 'highlights' => $highlights];
    }

    /** @param Site[] $sites */
    private function section(array $fields, array $sites): Section
    {
        $entries = Craft::$app->getEntries();
        $section = $entries->getSectionByHandle('articles');
        if ($section) {
            return $section;
        }

        $type = $entries->getEntryTypeByHandle('article');
        if (!$type) {
            $type = new EntryType(['name' => 'Article', 'handle' => 'article', 'icon' => 'newspaper', 'titleTranslationMethod' => 'site']);
            $type->setFieldLayout($this->layout(array_values($fields)));
            $this->save($entries->saveEntryType($type), $type, 'entry type article');
        }

        $section = new Section([
            'name' => 'Articles',
            'handle' => 'articles',
            'type' => Section::TYPE_CHANNEL,
            'propagationMethod' => PropagationMethod::All,
            'enableVersioning' => true,
        ]);
        $section->setEntryTypes([$type]);
        $section->setSiteSettings(array_map(fn(Site $site) => new Section_SiteSettings([
            'siteId' => $site->id,
            'enabledByDefault' => true,
            'hasUrls' => true,
            'uriFormat' => 'articles/{slug}',
            'template' => 'articles/_entry',
        ]), $sites));
        $this->save($entries->saveSection($section), $section, 'section articles');
        $this->log('Section Articles added.');

        return $section;
    }

    private function sampleEntry(Section $section): void
    {
        if (Entry::find()->sectionId($section->id)->status(null)->exists()) {
            return;
        }
        $type = $section->getEntryTypes()[0];
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $type->id,
            'siteId' => Craft::$app->getSites()->getPrimarySite()->id,
            'title' => 'Swiss chocolate, shipped worldwide',
            'slug' => 'swiss-chocolate-shipped-worldwide',
        ]);
        $entry->setFieldValues([
            'summary' => 'How a small family business in Bern brings handmade pralines to 40 countries.',
            'body' => '<h2>From Bern to the world</h2>'
                . '<p>Every praline is made by hand in our <strong>Bern</strong> workshop. Read more on <a href="https://www.supertext.com">our website</a>.</p>'
                . '<ul><li>Fresh ingredients from local farmers</li><li>Climate-neutral delivery within 48 hours</li></ul>',
            'highlights' => [
                'new1' => ['type' => 'highlight', 'title' => 'Handmade in Bern', 'fields' => ['text' => 'Every praline is filled and decorated by hand.']],
                'new2' => ['type' => 'highlight', 'title' => 'Shipped in 48 hours', 'fields' => ['text' => 'Cool packaging keeps the chocolate fresh on its way.']],
            ],
        ]);
        $this->save(Craft::$app->getElements()->saveElement($entry), $entry, 'sample article');
        $this->log('Sample article added.');
    }

    private function pluginSettings(): void
    {
        $plugins = Craft::$app->getPlugins();
        $plugin = $plugins->getPlugin('supertext-translation');
        if (!$plugin || ($plugin->getSettings()->languages ?? []) !== []) {
            return;
        }
        $plugins->savePluginSettings($plugin, [
            'apiKey' => '$SUPERTEXT_API_KEY',
            'apiUrl' => '$SUPERTEXT_API_URL',
            'environment' => 'live',
            'languages' => [
                'de' => ['code' => '', 'politeness' => 'more'],
                'fr' => ['code' => '', 'politeness' => 'more'],
                'it' => ['code' => '', 'politeness' => 'more'],
            ],
        ]);
        $this->log('Supertext settings saved (formal tone for German, French, Italian).');
    }

    /** @param Site[] $sites */
    private function editorsGroup(Section $section, array $sites): UserGroup
    {
        $users = Craft::$app->getUserGroups();
        $group = $users->getGroupByHandle('editors');
        if ($group) {
            return $group;
        }
        $group = new UserGroup(['name' => 'Editors', 'handle' => 'editors', 'description' => 'Edit and translate articles in every language']);
        $this->save($users->saveGroup($group), $group, 'user group editors');

        $permissions = ['accessCp', 'supertext-translation:translate'];
        foreach ($sites as $site) {
            $permissions[] = "editSite:{$site->uid}";
        }
        foreach (['viewEntries', 'createEntries', 'saveEntries', 'deleteEntries', 'viewPeerEntries', 'savePeerEntries', 'deletePeerEntries', 'viewPeerEntryDrafts', 'savePeerEntryDrafts'] as $action) {
            $permissions[] = "{$action}:{$section->uid}";
        }
        Craft::$app->getUserPermissions()->saveGroupPermissions($group->id, $permissions);
        $this->log('User group Editors added.');

        return $group;
    }

    /** Creates the account from <PREFIX>_EMAIL / _PASSWORD if it doesn't exist. Never changes existing accounts. */
    private function account(string $prefix, ?UserGroup $group): void
    {
        $email = trim((string) getenv("{$prefix}_EMAIL"));
        $password = (string) getenv("{$prefix}_PASSWORD");
        if ($email === '' || $password === '') {
            $this->log("{$prefix}_EMAIL / {$prefix}_PASSWORD not set; skipping that account.");

            return;
        }
        if (User::find()->email($email)->status(null)->exists() || User::find()->username($email)->status(null)->exists()) {
            $this->log("{$prefix}: account exists, left unchanged.");

            return;
        }

        $user = new User([
            'email' => $email,
            'username' => $email,
            'firstName' => 'Demo',
            'lastName' => $group ? 'Editor' : 'Admin',
            'admin' => $group === null,
            'active' => true,
            'newPassword' => $password,
        ]);
        $user->setScenario(User::SCENARIO_REGISTRATION);
        if (!Craft::$app->getElements()->saveElement($user)) {
            // Craft's rules: a valid e-mail address, a password of at least 6 characters.
            $this->stderr("[demo] {$prefix}: account not created (" . implode(' ', $user->getFirstErrors()) . "). Check {$prefix}_EMAIL / {$prefix}_PASSWORD.\n");

            return;
        }
        if ($group) {
            Craft::$app->getUsers()->assignUserToGroups($user->id, [$group->id]);
        }
        $this->log("{$prefix}: account created.");
    }

    /** The image installs Craft with a throwaway admin when DEMO_ADMIN_* is missing; remove it once a real admin exists. */
    private function removeInstaller(): void
    {
        $installer = User::find()->email('installer-*@example.com')->status(null)->one();
        $realAdmin = User::find()->admin(true)->status('active')->andWhere(['not like', 'users.email', 'installer-%@example.com', false])->exists();
        if ($installer && $realAdmin) {
            Craft::$app->getElements()->deleteElement($installer, true);
            $this->log('Installer account removed.');
        }
    }

    /** @param \craft\base\FieldInterface[] $fields */
    private function layout(array $fields): FieldLayout
    {
        return FieldLayout::createFromConfig([
            'type' => Entry::class,
            'tabs' => [[
                'name' => 'Content',
                'elements' => array_merge(
                    [['type' => EntryTitleField::class]],
                    array_map(fn($field) => ['type' => CustomField::class, 'fieldUid' => $field->uid], $fields),
                ),
            ]],
        ]);
    }

    private function save(bool $ok, $model, string $what): void
    {
        if (!$ok) {
            throw new \RuntimeException("Could not save {$what}: " . implode(' ', $model->getFirstErrors()));
        }
    }

    private function log(string $message): void
    {
        $this->stdout("[demo] {$message}\n");
    }
}
