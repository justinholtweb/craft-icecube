<?php

namespace justinholtweb\icecube;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\events\DefineHtmlEvent;
use craft\events\ModelEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\TemplateEvent;
use craft\helpers\ElementHelper;
use craft\helpers\Html;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\icecube\assetbundles\icecube\IcecubeAsset;
use justinholtweb\icecube\models\Settings;
use justinholtweb\icecube\services\Auth;
use justinholtweb\icecube\services\Locks;
use yii\base\Event;

/**
 * Icecube plugin — lock entries, assets, categories, and globals behind a password.
 *
 * @property-read Locks $locks
 * @property-read Auth $auth
 * @method Settings getSettings()
 */
class Icecube extends Plugin
{
    public const EDITION_STANDARD = 'standard';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function editions(): array
    {
        return [
            self::EDITION_STANDARD,
        ];
    }

    public static function config(): array
    {
        return [
            'components' => [
                'locks' => Locks::class,
                'auth' => Auth::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        if (!Craft::$app->getRequest()->getIsCpRequest()) {
            return;
        }

        $this->_registerEventHandlers();
        $this->_registerCpRoutes();
        $this->_registerPermissions();
        $this->_registerCpAssets();
        $this->_registerInlinePanels();
    }

    public function getLocks(): Locks
    {
        return $this->get('locks');
    }

    public function getAuth(): Auth
    {
        return $this->get('auth');
    }

    public function getCpNavItem(): ?array
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can('icecube:manageLocks')) {
            return null;
        }

        $item = parent::getCpNavItem();
        if (!$item) {
            return null;
        }

        $item['label'] = Craft::t('icecube', 'Icecube');
        $item['url'] = 'icecube/locks';
        return $item;
    }

    // ── Settings ──────────────────────────────────────────────

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate(
            'icecube/settings',
            ['settings' => $this->getSettings()]
        );
    }

    public function beforeSaveSettings(): bool
    {
        $request = Craft::$app->getRequest();
        $masterPassword = $request->getBodyParam('settings.masterPassword')
            ?? $request->getBodyParam('masterPassword');

        if (!empty($masterPassword)) {
            $this->getSettings()->masterPasswordHash = Auth::hashPassword($masterPassword);
        }

        return parent::beforeSaveSettings();
    }

    // ── Event handlers ────────────────────────────────────────

    private function _registerEventHandlers(): void
    {
        // Before save — entries, assets, categories
        // NOTE: Must use Element::EVENT_BEFORE_SAVE (not Elements::EVENT_BEFORE_SAVE_ELEMENT)
        // because Craft only honors $event->isValid on the element-level event.
        Event::on(
            Element::class,
            Element::EVENT_BEFORE_SAVE,
            function(ModelEvent $event) {
                /** @var Element $element */
                $element = $event->sender;
                $locks = $this->getLocks();

                // Allow draft autosaves through — we only block canonical saves
                if (ElementHelper::isDraftOrRevision($element)) {
                    return;
                }

                if (!$locks->isSupported($element)) {
                    return;
                }

                if (!$locks->isLockedForEdit($element)) {
                    return;
                }

                if ($this->_userCanBypass()) {
                    return;
                }

                if ($this->getAuth()->hasValidUnlock($element, 'edit')) {
                    return;
                }

                $event->isValid = false;
                $element->addError('icecube', Craft::t('icecube', 'This item is locked by Icecube. Enter the unlock password to save.'));
            }
        );

        // Before delete — entries, assets, categories
        Event::on(
            Element::class,
            Element::EVENT_BEFORE_DELETE,
            function(ModelEvent $event) {
                /** @var Element $element */
                $element = $event->sender;
                $locks = $this->getLocks();

                if (!$locks->isSupported($element)) {
                    return;
                }

                if (!$locks->isLockedForDelete($element)) {
                    return;
                }

                if ($this->_userCanBypass()) {
                    return;
                }

                if ($this->getAuth()->hasValidUnlock($element, 'delete')) {
                    return;
                }

                $event->isValid = false;
                Craft::$app->getSession()->setError(Craft::t('icecube', 'This item is locked by Icecube. Enter the unlock password to delete.'));
            }
        );

        // Before save — global sets
        Event::on(
            GlobalSet::class,
            GlobalSet::EVENT_BEFORE_SAVE,
            function(ModelEvent $event) {
                /** @var GlobalSet $globalSet */
                $globalSet = $event->sender;

                if (!$this->getLocks()->isLockedGlobal($globalSet, 'edit')) {
                    return;
                }

                if ($this->_userCanBypass()) {
                    return;
                }

                if ($this->getAuth()->hasValidUnlockForGlobal($globalSet, 'edit')) {
                    return;
                }

                $event->isValid = false;
                $globalSet->addError('icecube', Craft::t('icecube', 'This global set is locked by Icecube. Enter the unlock password to save.'));
            }
        );
    }

    // ── CP routes ─────────────────────────────────────────────

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['icecube'] = 'icecube/unlock/manage';
                $event->rules['icecube/unlock'] = 'icecube/unlock/validate';
                $event->rules['icecube/locks'] = 'icecube/unlock/manage';
                $event->rules['icecube/locks/save'] = 'icecube/unlock/save-lock';
                $event->rules['icecube/locks/delete'] = 'icecube/unlock/delete-lock';
                $event->rules['icecube/locks/inline-save'] = 'icecube/unlock/inline-save';
                $event->rules['icecube/locks/inline-delete'] = 'icecube/unlock/inline-delete';
            }
        );
    }

    // ── Permissions ───────────────────────────────────────────

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => Craft::t('icecube', 'Icecube'),
                    'permissions' => [
                        'icecube:manageLocks' => [
                            'label' => Craft::t('icecube', 'Manage locks'),
                        ],
                        'icecube:bypassLocks' => [
                            'label' => Craft::t('icecube', 'Bypass all locks without password'),
                        ],
                        'icecube:unlockLockedContent' => [
                            'label' => Craft::t('icecube', 'Unlock locked content with password'),
                        ],
                    ],
                ];
            }
        );
    }

    // ── CP asset injection ─────────────────────────────────────

    private function _registerCpAssets(): void
    {
        // Inject Icecube JS/CSS and lock metadata on element edit pages
        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            function(TemplateEvent $event) {
                $view = Craft::$app->getView();
                $locks = $this->getLocks();
                $auth = $this->getAuth();

                // Determine if we're on an element edit page and get the element
                $element = $this->_resolveEditingElement();
                if (!$element) {
                    return;
                }

                $lock = null;
                $targetType = null;
                $targetId = null;

                if ($element instanceof GlobalSet) {
                    if (!$locks->isLockedGlobal($element, 'edit') && !$locks->isLockedGlobal($element, 'delete')) {
                        return;
                    }
                    $lock = $locks->getMatchingGlobalLock($element);
                    $targetType = 'global';
                    $targetId = $element->id;
                } else {
                    if (!$locks->isSupported($element)) {
                        return;
                    }
                    $lock = $locks->getMatchingLock($element);
                    if (!$lock || !$lock->enabled) {
                        return;
                    }
                    $targetType = match (true) {
                        $element instanceof Entry => 'entry',
                        $element instanceof Asset => 'asset',
                        $element instanceof Category => 'category',
                        default => null,
                    };
                    $targetId = $element->id;
                }

                if (!$targetType || !$lock) {
                    return;
                }

                // Don't show modal for bypass users
                if ($this->_userCanBypass()) {
                    return;
                }

                $view->registerAssetBundle(IcecubeAsset::class);

                // Determine if a valid session unlock already exists
                if ($targetType === 'global') {
                    $editUnlocked = $auth->hasValidUnlockForGlobal($element, 'edit');
                } else {
                    $editUnlocked = $auth->hasValidUnlock($element, 'edit');
                }

                // Auto-prompt on page load if locked for edit and not yet unlocked this session
                $autoPrompt = ($lock->lockEdit && !$editUnlocked) ? '1' : '0';

                // Inject metadata element for JS
                $metaHtml = Html::tag('div', '', [
                    'id' => 'icecube-meta',
                    'data-target-type' => $targetType,
                    'data-target-id' => (string)$targetId,
                    'data-lock-edit' => $lock->lockEdit ? '1' : '0',
                    'data-lock-delete' => $lock->lockDelete ? '1' : '0',
                    'data-edit-unlocked' => $editUnlocked ? '1' : '0',
                    'data-auto-prompt' => $autoPrompt,
                    'data-notes' => (string)($lock->notes ?? ''),
                    'style' => 'display:none;',
                ]);
                $view->registerHtml($metaHtml);

                // Inject the unlock modal template
                $modalHtml = $view->renderTemplate('icecube/_components/unlock-modal');
                $view->registerHtml($modalHtml);

                // Inject lock badge into the page
                $badgeText = Craft::t('icecube', 'Locked by Icecube');
                if ($lock->notes) {
                    $badgeText .= ' — ' . $lock->notes;
                }
                $view->registerHtml(Html::tag('div', Html::encode($badgeText), [
                    'class' => 'icecube-badge icecube-badge--locked',
                    'style' => 'margin-bottom:14px;',
                ]));
            }
        );
    }

    // ── Inline lock-management panels ─────────────────────────

    private function _registerInlinePanels(): void
    {
        // Element edit sidebars (Entry, Asset, Category, GlobalSet)
        Event::on(
            Element::class,
            Element::EVENT_DEFINE_SIDEBAR_HTML,
            function(DefineHtmlEvent $event) {
                /** @var Element $element */
                $element = $event->sender;
                $html = $this->_renderInlinePanel($element);
                if ($html !== null) {
                    $event->html = $html . $event->html;
                }
            }
        );

        // Global-set edit page uses the cp.globals.edit template hook
        Craft::$app->getView()->hook('cp.globals.edit', function(array &$context) {
            /** @var GlobalSet|null $globalSet */
            $globalSet = $context['globalSet'] ?? null;
            if (!$globalSet) {
                return '';
            }
            return $this->_renderInlinePanel($globalSet) ?? '';
        });
    }

    /**
     * Render the inline lock-management panel for an element, or return null
     * if the current user/element shouldn't see it.
     */
    private function _renderInlinePanel(mixed $element): ?string
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user || !$user->can('icecube:manageLocks')) {
            return null;
        }

        // Resolve the canonical ID — Craft 5 opens entries as provisional drafts
        if ($element instanceof Element) {
            $canonicalId = $element->getCanonicalId();
        } else {
            $canonicalId = $element->id ?? null;
        }
        if (empty($canonicalId)) {
            return null;
        }

        // Skip revisions (read-only version history); allow drafts through
        if ($element instanceof Element && $element->getIsRevision()) {
            return null;
        }

        $settings = $this->getSettings();
        $targetType = null;
        $label = null;

        if ($element instanceof Entry) {
            if (!$settings->enableEntries) {
                return null;
            }
            $targetType = 'entry';
            $label = Craft::t('icecube', 'entry');
        } elseif ($element instanceof Asset) {
            if (!$settings->enableAssets) {
                return null;
            }
            $targetType = 'asset';
            $label = Craft::t('icecube', 'asset');
        } elseif ($element instanceof Category) {
            if (!$settings->enableCategories) {
                return null;
            }
            $targetType = 'category';
            $label = Craft::t('icecube', 'category');
        } elseif ($element instanceof GlobalSet) {
            if (!$settings->enableGlobals) {
                return null;
            }
            $targetType = 'global';
            $label = Craft::t('icecube', 'global set');
        } else {
            return null;
        }

        $view = Craft::$app->getView();
        $view->registerAssetBundle(IcecubeAsset::class);

        $lock = $this->getLocks()->getDirectLock($targetType, (int)$canonicalId);

        return $view->renderTemplate('icecube/_components/lock-panel', [
            'targetType' => $targetType,
            'targetId' => (int)$canonicalId,
            'label' => $label,
            'lock' => $lock,
        ]);
    }

    /**
     * Try to resolve the element currently being edited.
     *
     * Handles Craft 5 URL patterns:
     *   /admin/content/entries/<section>/<id>-<slug>
     *   /admin/entries/<section>/<id>-<slug>
     *   /admin/assets/<volume>/<id>-<filename>
     *   /admin/categories/<group>/<id>-<slug>
     *   /admin/globals/<handle>
     */
    private function _resolveEditingElement(): mixed
    {
        $request = Craft::$app->getRequest();
        $segments = $request->getSegments();

        if (!$segments) {
            return null;
        }

        // Global sets: globals/<handle>   (or content/globals/<handle>)
        $globalsIndex = null;
        foreach ($segments as $i => $seg) {
            if ($seg === 'globals' && isset($segments[$i + 1])) {
                $globalsIndex = $i;
                break;
            }
        }
        if ($globalsIndex !== null) {
            $handle = $segments[$globalsIndex + 1];
            // Strip a possible site handle suffix or leading id-
            $handle = preg_replace('/^\d+-/', '', $handle);
            $globalSet = Craft::$app->getGlobals()->getSetByHandle($handle);
            if ($globalSet) {
                return $globalSet;
            }
        }

        // Scan segments for the first one that starts with "<id>-..." or is purely numeric
        $lastSegment = end($segments);
        $id = null;
        if (is_numeric($lastSegment)) {
            $id = (int)$lastSegment;
        } elseif (preg_match('/^(\d+)(?:-|$)/', (string)$lastSegment, $m)) {
            $id = (int)$m[1];
        }

        if ($id) {
            $element = Craft::$app->getElements()->getElementById($id);
            if ($element) {
                return $element;
            }
        }

        return null;
    }

    // ── Helpers ────────────────────────────────────────────────

    private function _userCanBypass(): bool
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return false;
        }

        $settings = $this->getSettings();

        // Admin users: bypass only if setting is enabled.
        // (Craft's ->can() returns true for admins on every permission, so we
        // must handle them explicitly before falling through to permission check.)
        if ($user->admin) {
            return (bool)$settings->adminsBypass;
        }

        // Non-admin users with explicit bypass permission
        if ($user->can('icecube:bypassLocks')) {
            return true;
        }

        return false;
    }
}
