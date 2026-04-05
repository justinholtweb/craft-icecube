<?php

namespace justinholtweb\icecube\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use justinholtweb\icecube\Icecube;
use justinholtweb\icecube\records\LockRecord;

class Locks extends Component
{
    // ── Supported check ───────────────────────────────────────

    /**
     * Is this element a type Icecube can lock?
     */
    public function isSupported(mixed $element): bool
    {
        $settings = Icecube::getInstance()->getSettings();

        if ($element instanceof Entry && $settings->enableEntries) {
            return true;
        }
        if ($element instanceof Asset && $settings->enableAssets) {
            return true;
        }
        if ($element instanceof Category && $settings->enableCategories) {
            return true;
        }

        return false;
    }

    // ── Element locks ─────────────────────────────────────────

    public function isLockedForEdit(mixed $element): bool
    {
        $lock = $this->getMatchingLock($element);
        return $lock && $lock->lockEdit && $lock->enabled;
    }

    public function isLockedForDelete(mixed $element): bool
    {
        $lock = $this->getMatchingLock($element);
        return $lock && $lock->lockDelete && $lock->enabled;
    }

    /**
     * Find the most specific lock that applies to an element.
     * Priority: direct element lock > scope rule lock.
     */
    public function getMatchingLock(mixed $element): ?LockRecord
    {
        $targetType = $this->_resolveTargetType($element);
        if (!$targetType) {
            return null;
        }

        // 1. Direct element lock
        $direct = LockRecord::find()
            ->where([
                'targetType' => $targetType,
                'targetId' => $element->id,
                'scope' => 'element',
                'enabled' => true,
            ])
            ->one();

        if ($direct) {
            return $direct;
        }

        // 2. Rule-based lock (section / volume / category group)
        $scopeInfo = $this->_resolveScope($element);
        if ($scopeInfo) {
            return LockRecord::find()
                ->where([
                    'targetType' => $targetType,
                    'scope' => $scopeInfo['scope'],
                    'scopeId' => $scopeInfo['scopeId'],
                    'enabled' => true,
                ])
                ->one();
        }

        return null;
    }

    // ── Global-set locks ──────────────────────────────────────

    public function isLockedGlobal(GlobalSet $globalSet, string $action): bool
    {
        $settings = Icecube::getInstance()->getSettings();
        if (!$settings->enableGlobals) {
            return false;
        }

        $lock = $this->getMatchingGlobalLock($globalSet);
        if (!$lock || !$lock->enabled) {
            return false;
        }

        return $action === 'edit' ? (bool)$lock->lockEdit : (bool)$lock->lockDelete;
    }

    public function getMatchingGlobalLock(GlobalSet $globalSet): ?LockRecord
    {
        // Direct global-set lock by ID
        $direct = LockRecord::find()
            ->where([
                'targetType' => 'global',
                'targetId' => $globalSet->id,
                'scope' => 'element',
                'enabled' => true,
            ])
            ->one();

        if ($direct) {
            return $direct;
        }

        // Rule: lock by global-set handle (stored as scopeId = globalSet id)
        return LockRecord::find()
            ->where([
                'targetType' => 'global',
                'scope' => 'globalSet',
                'scopeId' => $globalSet->id,
                'enabled' => true,
            ])
            ->one();
    }

    /**
     * Get only a direct element-scope lock (ignores rule-based matches).
     * Used by inline lock panels.
     */
    public function getDirectLock(string $targetType, int $targetId): ?LockRecord
    {
        return LockRecord::find()
            ->where([
                'targetType' => $targetType,
                'targetId' => $targetId,
                'scope' => 'element',
            ])
            ->one();
    }

    // ── All locks ─────────────────────────────────────────────

    /**
     * @return LockRecord[]
     */
    public function getAllLocks(): array
    {
        return LockRecord::find()
            ->orderBy(['dateCreated' => SORT_DESC])
            ->all();
    }

    /**
     * Save or update a lock record.
     */
    public function saveLock(LockRecord $record): bool
    {
        return $record->save();
    }

    /**
     * Delete a lock by ID.
     */
    public function deleteLockById(int $id): bool
    {
        $record = LockRecord::findOne($id);
        if (!$record) {
            return false;
        }
        return (bool)$record->delete();
    }

    // ── Password hash for a lock ──────────────────────────────

    /**
     * Return the password hash to verify against — per-lock hash or master.
     */
    public function getPasswordHashForLock(?LockRecord $lock): string
    {
        if ($lock && !empty($lock->passwordHash)) {
            return $lock->passwordHash;
        }

        return Icecube::getInstance()->getSettings()->masterPasswordHash;
    }

    // ── Internals ─────────────────────────────────────────────

    private function _resolveTargetType(mixed $element): ?string
    {
        if ($element instanceof Entry) {
            return 'entry';
        }
        if ($element instanceof Asset) {
            return 'asset';
        }
        if ($element instanceof Category) {
            return 'category';
        }
        return null;
    }

    private function _resolveScope(mixed $element): ?array
    {
        if ($element instanceof Entry && $element->sectionId) {
            return ['scope' => 'section', 'scopeId' => $element->sectionId];
        }
        if ($element instanceof Asset && $element->volumeId) {
            return ['scope' => 'volume', 'scopeId' => $element->volumeId];
        }
        if ($element instanceof Category && $element->groupId) {
            return ['scope' => 'group', 'scopeId' => $element->groupId];
        }
        return null;
    }
}
