<?php
defined('ABSPATH') || exit;

// Heartbeat wrapper (cron hook calls osc_run_heartbeat from remote.php)
function osc_heartbeat_status(): array {
    $last = osc_get_option('last_heartbeat', 0);
    return [
        'last_heartbeat' => $last,
        'is_recent'      => $last > (time() - 600), // within 10 min
    ];
}
