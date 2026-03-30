<?php

namespace justinholtweb\icecube\services;

use Craft;
use craft\base\Component;
use craft\elements\GlobalSet;
use justinholtweb\icecube\Icecube;
use justinholtweb\icecube\records\LockRecord;

class Auth extends Component
{
    // ── Session-based unlock checks ───────────────────────────

    /**
     * Does the current session have a valid unlock for this element + action?
     */
    public function hasValidUnlock(mixed $element, string $action): bool
    {
        $key = $this->_sessionKey($this->_resolveTargetType($element), $element->id, $action);
        return $this->_isSessionKeyValid($key);
    }

    /**
     * Does the current session have a valid unlock for this global set + action?
     */
    public function hasValidUnlockForGlobal(GlobalSet $globalSet, string $action): bool
    {
        $key = $this->_sessionKey('global', $globalSet->id, $action);
        return $this->_isSessionKeyValid($key);
    }

    // ── Grant / revoke unlock ─────────────────────────────────

    /**
     * Verify password and grant a time-limited session unlock.
     * Returns true if the password was correct.
     */
    public function attemptUnlock(string $targetType, int $targetId, string $action, string $password): bool
    {
        $lock = $this->_findLock($targetType, $targetId);
        $hash = Icecube::getInstance()->locks->getPasswordHashForLock($lock);

        if (empty($hash)) {
            // No password configured — nothing to verify against
            return false;
        }

        if (!password_verify($password, $hash)) {
            return false;
        }

        $this->grantUnlock($targetType, $targetId, $action);
        return true;
    }

    /**
     * Grant session unlock without password check (used after verification).
     */
    public function grantUnlock(string $targetType, int $targetId, string $action): void
    {
        $key = $this->_sessionKey($targetType, $targetId, $action);
        $ttl = Icecube::getInstance()->getSettings()->unlockTtlMinutes;
        $expiresAt = time() + ($ttl * 60);

        Craft::$app->getSession()->set($key, $expiresAt);
    }

    // ── Password hashing ──────────────────────────────────────

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    // ── Internals ─────────────────────────────────────────────

    private function _sessionKey(string $targetType, int $targetId, string $action): string
    {
        return "icecube.unlock.{$targetType}.{$targetId}.{$action}";
    }

    private function _isSessionKeyValid(string $key): bool
    {
        $expiresAt = Craft::$app->getSession()->get($key);
        if (!$expiresAt) {
            return false;
        }
        if (time() > $expiresAt) {
            Craft::$app->getSession()->remove($key);
            return false;
        }
        return true;
    }

    private function _resolveTargetType(mixed $element): string
    {
        return match (true) {
            $element instanceof \craft\elements\Entry => 'entry',
            $element instanceof \craft\elements\Asset => 'asset',
            $element instanceof \craft\elements\Category => 'category',
            default => 'unknown',
        };
    }

    private function _findLock(string $targetType, int $targetId): ?LockRecord
    {
        if ($targetType === 'global') {
            $globalSet = Craft::$app->getGlobals()->getSetById($targetId);
            if ($globalSet) {
                return Icecube::getInstance()->locks->getMatchingGlobalLock($globalSet);
            }
            return null;
        }

        $element = Craft::$app->getElements()->getElementById($targetId);
        if ($element) {
            return Icecube::getInstance()->locks->getMatchingLock($element);
        }
        return null;
    }
}
