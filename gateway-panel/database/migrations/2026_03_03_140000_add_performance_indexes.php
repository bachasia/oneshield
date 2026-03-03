<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes for high-traffic queries:
 *
 * transactions:
 *   - (site_id, gateway, status, created_at) → spin income_limit check in SiteRouterService
 *   - (gateway, status, created_at)           → panel transaction list filters
 *
 * shield_sites:
 *   - (user_id, is_active, last_heartbeat_at) → routing query heartbeat filter
 *   - (user_id, is_active, failure_count)     → circuit breaker filter
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── transactions ────────────────────────────────────────────────────
        Schema::table('transactions', function (Blueprint $table) {
            // Used by passesSpinLimits(): WHERE site_id=? AND gateway=? AND status='completed' AND created_at>=?
            if (!$this->indexExists('transactions', 'transactions_site_gateway_status_created_idx')) {
                $table->index(
                    ['site_id', 'gateway', 'status', 'created_at'],
                    'transactions_site_gateway_status_created_idx'
                );
            }

            // Used by panel TransactionController filter: WHERE gateway=? AND status=? AND created_at BETWEEN ?
            if (!$this->indexExists('transactions', 'transactions_gateway_status_created_idx')) {
                $table->index(
                    ['gateway', 'status', 'created_at'],
                    'transactions_gateway_status_created_idx'
                );
            }
        });

        // ── shield_sites ─────────────────────────────────────────────────────
        $table_name = Schema::hasTable('shield_sites') ? 'shield_sites' : 'mesh_sites';

        Schema::table($table_name, function (Blueprint $table) use ($table_name) {
            // Used by selectSite(): WHERE user_id=? AND is_active=1 AND last_heartbeat_at>=?
            if (!$this->indexExists($table_name, 'shield_sites_user_active_heartbeat_idx')) {
                $table->index(
                    ['user_id', 'is_active', 'last_heartbeat_at'],
                    'shield_sites_user_active_heartbeat_idx'
                );
            }

            // Used by selectSite() + fallbackSelect(): WHERE user_id=? AND is_active=1 AND failure_count<?
            if (!$this->indexExists($table_name, 'shield_sites_user_active_failures_idx')) {
                $table->index(
                    ['user_id', 'is_active', 'failure_count'],
                    'shield_sites_user_active_failures_idx'
                );
            }
        });
    }

    public function down(): void
    {
        $driver = \Illuminate\Support\Facades\DB::getDriverName();

        Schema::table('transactions', function (Blueprint $table) use ($driver) {
            if ($driver !== 'sqlite') {
                $table->dropIndexIfExists('transactions_site_gateway_status_created_idx');
                $table->dropIndexIfExists('transactions_gateway_status_created_idx');
            }
        });

        $table_name = Schema::hasTable('shield_sites') ? 'shield_sites' : 'mesh_sites';

        Schema::table($table_name, function (Blueprint $table) use ($driver) {
            if ($driver !== 'sqlite') {
                $table->dropIndexIfExists('shield_sites_user_active_heartbeat_idx');
                $table->dropIndexIfExists('shield_sites_user_active_failures_idx');
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = \Illuminate\Support\Facades\DB::getDriverName();

        // SQLite (used in tests) does not support SHOW INDEX — always return false
        // so the index creation is attempted and SQLite handles duplicates gracefully.
        if ($driver === 'sqlite') {
            return false;
        }

        $indexes = \Illuminate\Support\Facades\DB::select(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = ?",
            [$indexName]
        );
        return count($indexes) > 0;
    }
};
