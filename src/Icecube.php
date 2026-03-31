<?php

namespace justinholtweb\icecube;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\events\ElementEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\ElementHelper;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\View;
use craft\events\RegisterUrlRulesEvent;
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
 */
class Icecube extends Plugin
{
    public const EDITION_STANDARD = 'standard';

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = false;

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

    // ── Event handlers ────────────────────────────────────────

    private function _registerEventHandlers(): void
    {
        // Before save — entries, assets, categories
        Event::on(
            Elements::class,
            Elements::EVENT_BEFORE_SAVE_ELEMENT,
            function (ElementEvent $event) {
                $element = $event->element;

                // Allow draft autosaves through unless it's a canonical save
                if (ElementHelper::isDraftOrRevision($element)) {
                    return;
                }

                if (!$this->locks->isSupported($element)) {
                    return;
                }

                if (!$this->locks->isLockedForEdit($element)) {
                    return;
                }

                // Check if user can bypass
                if ($this->_userCanBypass()) {
                    return;
                }

                if ($this->auth->hasValidUnlock($element, 'edit')) {
                    return;
                }

                $event->isValid = false;
                $element->addError('icecube', 'This item is locked by Icecube. Enter the unlock password to save.');
            }
        );

        // Before delete — entries, assets, categories
        Event::on(
            Elements::class,
            Elements::EVENT_BEFORE_DELETE_ELEMENT,
            function (ElementEvent $event) {
                $element = $event->element;

                if (!$this->locks->isSupported($element)) {
                    return;
                }

                if (!$this->locks->isLockedForDelete($element)) {
                    return;
                }

                if ($this->_userCanBypass()) {
                    return;
                }

                if ($this->auth->hasValidUnlock($element, 'delete')) {
                    return;
                }

                $event->isValid = false;
                Craft::$app->getSession()->setError('This item is locked by Icecube. Enter the unlock password to delete.');
            }
        );

        // Before save — global sets
        Event::on(
            GlobalSet::class,
            GlobalSet::EVENT_BEFORE_SAVE,
            function (\craft\events\ModelEvent $event) {
                /** @var GlobalSet $globalSet */
                $globalSet = $event->sender;

                if (!$this->locks->isLockedGlobal($globalSet, 'edit')) {
                    return;
                }

                if ($this->_userCanBypass()) {
                    return;
                }

                if ($this->auth->hasValidUnlockForGlobal($globalSet, 'edit')) {
                    return;
                }

                $event->isValid = false;
                $globalSet->addError('icecube', 'This global set is locked by Icecube. Enter the unlock password to save.');
            }
        );
    }

    // ── CP routes ─────────────────────────────────────────────

    private function _registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['icecube/unlock'] = 'icecube/unlock/validate';
                $event->rules['icecube/locks'] = 'icecube/unlock/manage';
                $event->rules['icecube/locks/save'] = 'icecube/unlock/save-lock';
                $event->rules['icecube/locks/delete'] = 'icecube/unlock/delete-lock';
            }
        );
    }

    // ── Permissions ───────────────────────────────────────────

    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'Icecube',
                    'permissions' => [
                        'icecube:manageLocks' => [
                            'label' => 'Manage locks',
                        ],
                        'icecube:bypassLocks' => [
                            'label' => 'Bypass all locks without password',
                        ],
                        'icecube:unlockLockedContent' => [
                            'label' => 'Unlock locked content with password',
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
            function (\craft\events\TemplateEvent $event) {
                $view = Craft::$app->getView();

                // Determine if we're on an element edit page and get the element
                $element = $this->_resolveEditingElement();
                if (!$element) {
                    return;
                }

                $lock = null;
                $targetType = null;
                $targetId = null;

                if ($element instanceof GlobalSet) {
                    if (!$this->locks->isLockedGlobal($element, 'edit') && !$this->locks->isLockedGlobal($element, 'delete')) {
                        return;
                    }
                    $lock = $this->locks->getMatchingGlobalLock($element);
                    $targetType = 'global';
                    $targetId = $element->id;
                } else {
                    if (!$this->locks->isSupported($element)) {
                        return;
                    }
                    $lock = $this->locks->getMatchingLock($element);
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

                // Inject metadata element for JS
                $lockEdit = $lock->lockEdit ? '1' : '0';
                $lockDelete = $lock->lockDelete ? '1' : '0';
                $notes = htmlspecialchars($lock->notes ?? '', ENT_QUOTES);
                $view->registerHtml(
                    "<div id=\"icecube-meta\" data-target-type=\"{$targetType}\" data-target-id=\"{$targetId}\" data-lock-edit=\"{$lockEdit}\" data-lock-delete=\"{$lockDelete}\" data-notes=\"{$notes}\" style=\"display:none;\"></div>"
                );

                // Inject the unlock modal template
                $modalHtml = $view->renderTemplate('icecube/_components/unlock-modal');
                $view->registerHtml($modalHtml);

                // Inject lock badge into the page
                $badgeHtml = '<div class="icecube-badge icecube-badge--locked" style="margin-bottom:14px;">';
                $badgeHtml .= 'Locked by Icecube';
                if ($lock->notes) {
                    $badgeHtml .= ' — ' . htmlspecialchars($lock->notes);
                }
                $badgeHtml .= '</div>';
                $view->registerHtml($badgeHtml);
            }
        );
    }

    /**
     * Try to resolve the element currently being edited.
     */
    private function _resolveEditingElement(): mixed
    {
        $request = Craft::$app->getRequest();
        $segments = $request->getSegments();

        // entries/section-handle/entry-id, assets/volume-handle/asset-id, etc.
        if (count($segments) >= 2) {
            $lastSegment = end($segments);
            if (is_numeric($lastSegment)) {
                $element = Craft::$app->getElements()->getElementById((int)$lastSegment);
                if ($element) {
                    return $element;
                }
            }
        }

        // Global sets: globals/handle
        if (count($segments) === 2 && $segments[0] === 'globals') {
            $globalSet = Craft::$app->getGlobals()->getSetByHandle($segments[1]);
            if ($globalSet) {
                return $globalSet;
            }
        }

        return null;
    }

    // ── Settings save — hash master password ──────────────────

    public function beforeSaveSettings(): bool
    {
        $request = Craft::$app->getRequest();
        $masterPassword = $request->getBodyParam('settings.masterPassword') ?? $request->getBodyParam('masterPassword');

        if (!empty($masterPassword)) {
            $this->getSettings()->masterPasswordHash = Auth::hashPassword($masterPassword);
        }

        return parent::beforeSaveSettings();
    }

    // ── Helpers ────────────────────────────────────────────────

    private function _userCanBypass(): bool
    {
        $user = Craft::$app->getUser()->getIdentity();
        if (!$user) {
            return false;
        }

        /** @var Settings $settings */
        $settings = $this->getSettings();

        // Admins bypass if setting is enabled
        if ($settings->adminsBypass && $user->admin) {
            return true;
        }

        // Users with bypass permission
        if ($user->can('icecube:bypassLocks')) {
            return true;
        }

        return false;
    }
}
