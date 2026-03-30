<?php

namespace justinholtweb\icecube\migrations;

use craft\db\Migration;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable('{{%icecube_locks}}', [
            'id' => $this->primaryKey(),
            'targetType' => $this->string(20)->notNull(), // entry, asset, category, global
            'targetId' => $this->integer()->null(),       // specific element/global set id
            'scope' => $this->string(20)->notNull()->defaultValue('element'), // element, section, volume, group, globalSet
            'scopeId' => $this->integer()->null(),        // id of the section/volume/group/globalSet for rule-based locks
            'passwordHash' => $this->text()->null(),      // per-lock password hash (falls back to master)
            'lockEdit' => $this->boolean()->notNull()->defaultValue(true),
            'lockDelete' => $this->boolean()->notNull()->defaultValue(true),
            'notes' => $this->text()->null(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // Index for fast element-level lookups
        $this->createIndex(null, '{{%icecube_locks}}', ['targetType', 'targetId']);
        // Index for rule-based lookups
        $this->createIndex(null, '{{%icecube_locks}}', ['targetType', 'scope', 'scopeId']);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%icecube_locks}}');
        return true;
    }
}
