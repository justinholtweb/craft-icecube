<?php

namespace justinholtweb\icecube\records;

use craft\db\ActiveRecord;

/**
 * @property int    $id
 * @property string $targetType    entry|asset|category|global
 * @property int|null $targetId   elementId or globalSetId (null for rule-based)
 * @property string $scope        element|section|volume|group|globalSet
 * @property int|null $scopeId    sectionId, volumeId, categoryGroupId, or globalSetId for rules
 * @property string $passwordHash bcrypt hash — falls back to master password if empty
 * @property bool   $lockEdit
 * @property bool   $lockDelete
 * @property string|null $notes
 * @property bool   $enabled
 */
class LockRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%icecube_locks}}';
    }
}
