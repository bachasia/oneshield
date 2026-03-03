<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rename legacy mesh_sites_* index names to shield_sites_* on the shield_sites table.
 *
 * When MySQL renames a table it does NOT rename the indexes, so the old
 * mesh_sites_* names survived the earlier rename migration.
 */
return new class extends Migration
{
    private array $renames = [
        'mesh_sites_site_key_unique'       => 'shield_sites_site_key_unique',
        'mesh_sites_user_id_is_active_index' => 'shield_sites_user_id_is_active_index',
        'mesh_sites_group_id_is_active_index' => 'shield_sites_group_id_is_active_index',
    ];

    public function up(): void
    {
        // SQLite (tests) does not support RENAME INDEX — skip safely
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if (!Schema::hasTable('shield_sites')) {
            return;
        }

        foreach ($this->renames as $oldName => $newName) {
            if ($this->indexExists('shield_sites', $oldName)) {
                DB::statement("ALTER TABLE `shield_sites` RENAME INDEX `{$oldName}` TO `{$newName}`");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        if (!Schema::hasTable('shield_sites')) {
            return;
        }

        foreach ($this->renames as $oldName => $newName) {
            if ($this->indexExists('shield_sites', $newName)) {
                DB::statement("ALTER TABLE `shield_sites` RENAME INDEX `{$newName}` TO `{$oldName}`");
            }
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return count(DB::select(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
            [$indexName]
        )) > 0;
    }
};
