<?php

namespace justinholtweb\icecube\models;

use craft\base\Model;

class Settings extends Model
{
    /** Master password hash — used when no per-lock password is set. */
    public string $masterPasswordHash = '';

    /** Whether admin users bypass all locks automatically. */
    public bool $adminsBypass = true;

    /** Enable locking for entries. */
    public bool $enableEntries = true;

    /** Enable locking for assets. */
    public bool $enableAssets = true;

    /** Enable locking for categories. */
    public bool $enableCategories = true;

    /** Enable locking for global sets. */
    public bool $enableGlobals = true;

    /** Minutes an unlock session stays valid. */
    public int $unlockTtlMinutes = 10;

    public function defineRules(): array
    {
        return [
            [['unlockTtlMinutes'], 'integer', 'min' => 1, 'max' => 1440],
            [['adminsBypass', 'enableEntries', 'enableAssets', 'enableCategories', 'enableGlobals'], 'boolean'],
        ];
    }
}
