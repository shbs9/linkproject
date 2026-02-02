<?php
/**
 * Plugin Name: Daily Cache Purge (MU)
 * Description: Purges Batcache or WP Edge Cache at configured UTC times via WP-Cron. Logs to text file.
 * Version: 1.3.0
 * Author: Custom Development
 * Requires: WP-CLI installed and shell_exec() enabled
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Daily_Cache_Purge {

    const CRON_HOOK = 'daily_cache_purge_event';
    const LOG_FILE_PATH = '~/htdocs/cache-purge-log.txt';
    const ADMIN_NOTICE_OPTION = 'cache_purge_admin_notice_dismissed';

    /**
     * Define purge times in 24-hour UTC format (0-23)
     * Each entry will create a separate daily cron job
     * Example: [0, 12, 18] will purge at midnight UTC, noon UTC, and 6 PM UTC
     */
    const PURGE_TIMES = [10, 11, 12]; // 10:00 UTC = 05:00 EST / 03:00 MST
                                       // 11:00 UTC = 06:00 EST / 04:00 MST
                                       // 12:00 UTC = 07:00 EST / 05:00 MST

    public static function init() {
        // Schedule WP-Cron event
        add_action('plugins_loaded', [__CLASS__, 'schedule_purge']);

        // Hook for the actual purge
        add_action(self::CRON_HOOK, [__CLASS__, 'execute_purge']);

        // Check if purge is overdue (fallback)
        add_action('init', [__CLASS__, 'check_if_overdue']);

        // Admin notices
        add_action('admin_notices', [__CLASS__, 'admin_notices']);

        // AJAX handler for dismissing admin notice
        add_action('wp_ajax_dismiss_cache_purge_notice', [__CLASS__, 'dismiss_admin_notice']);
    }

    public static function schedule_purge() {
        // Clear all existing scheduled events first
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }

        // Schedule a cron job for each defined time (using UTC)
        foreach (self::PURGE_TIMES as $hour) {
            // Validate hour is between 0-23
            if ($hour < 0 || $hour > 23) {
                error_log('[Cache Purge] Invalid hour in PURGE_TIMES: ' . $hour);
                continue;
            }

            // Calculate next occurrence of this hour in UTC
            $now_utc = time(); // Unix timestamp is always UTC
            $today_utc = gmdate('Y-m-d', $now_utc);
            $target_time = strtotime($today_utc . ' ' . $hour . ':00:00 UTC');
            
            // If time has passed today, schedule for tomorrow
            if ($target_time <= $now_utc) {
                $target_time = strtotime('tomorrow ' . $hour . ':00:00 UTC', $now_utc);
            }

            // Create unique hook name for each time
            $hook_name = self::CRON_HOOK . '_' . $hour;
            
            // Check if already scheduled
            if (!wp_next_scheduled($hook_name)) {
                wp_schedule_event($target_time, 'daily', $hook_name);
                error_log(sprintf(
                    '[Cache Purge] WP-Cron event scheduled for hour %d UTC: %s (WP time: %s)',
                    $hour,
                    gmdate('Y-m-d H:i:s', $target_time) . ' UTC',
                    get_date_from_gmt(gmdate('Y-m-d H:i:s', $target_time))
                ));
            }

            // Hook the execution function to this specific event
            add_action($hook_name, function() use ($hour) {
                self::execute_purge('wp-cron-' . $hour . 'h-utc');
            });
        }
    }

    public static function check_if_overdue() {
        // Don't run during WP-CLI execution to prevent infinite loops
        if (defined('WP_CLI') && WP_CLI) {
            return;
        }
        
        if (is_admin() || defined('DOING_AJAX')) return;

        $log_file = self::get_log_file_path();
        
        // Get last successful purge from log file
        $last_purge_time = null;
        if (file_exists($log_file)) {
            $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines) {
                // Read from bottom up to find last success
                for ($i = count($lines) - 1; $i >= 0; $i--) {
                    if (strpos($lines[$i], 'SUCCESS') !== false) {
                        // Extract UTC timestamp from log line
                        preg_match('/\[UTC:\s*(.*?)\s*\|/', $lines[$i], $matches);
                        if (isset($matches[1])) {
                            $last_purge_time = strtotime($matches[1] . ' UTC');
                            break;
                        }
                    }
                }
            }
        }

        $now_utc = time();
        if (!$last_purge_time || ($last_purge_time < ($now_utc - (25 * 3600)))) {
            error_log('[Cache Purge] Overdue purge detected, triggering now');
            self::execute_purge('overdue-fallback');
        }
    }

    public static function execute_purge($triggered_by = 'wp-cron') {
        $start_time = microtime(true);
        error_log('[Cache Purge] Starting purge, triggered by: ' . $triggered_by);

        $result = self::purge_cache();

        $execution_time = microtime(true) - $start_time;

        if ($result['success']) {
            error_log('[Cache Purge] SUCCESS: Cache purged in ' . $execution_time . ' seconds');
            self::log_purge_event('success', $result['output'], '', $execution_time, $triggered_by);
        } else {
            error_log('[Cache Purge] FAILURE: ' . $result['error']);
            self::log_purge_event('failure', $result['output'], $result['error'], $execution_time, $triggered_by);
        }
    }

    private static function purge_cache() {
        // CRITICAL: Prevent infinite loop when WP-CLI calls itself
        // Check if we're being called from within a WP-CLI subprocess
        static $purge_in_progress = false;
        
        if ($purge_in_progress) {
            return ['success' => false, 'output' => '', 'error' => 'Purge already in progress (prevented recursion)'];
        }
        
        $purge_in_progress = true;
        
        // 1. Try Batcache
        global $batcache;
        
        if (isset($batcache)) {
            try {
                // Check if batcache params are in an object or an array
                if (is_object($batcache)) {
                    // For object format, call flush() method
                    if (method_exists($batcache, 'flush')) {
                        $batcache->flush();
                        $purge_in_progress = false;
                        return ['success' => true, 'output' => 'Batcache flushed (object)', 'error' => ''];
                    }
                } elseif (is_array($batcache)) {
                    // For array format, still need to call flush via the batcache object
                    // Create a temporary reference to call flush
                    if (function_exists('batcache_clear_url')) {
                        batcache_clear_url(home_url('/'));
                        $purge_in_progress = false;
                        return ['success' => true, 'output' => 'Batcache cleared', 'error' => ''];
                    }
                }
            } catch (Exception $e) {
                $purge_in_progress = false;
                return ['success' => false, 'output' => '', 'error' => 'Batcache flush failed: ' . $e->getMessage()];
            }
        }

        // 2. Try WP Edge Cache directly if available
        if (function_exists('wp_edge_cache_purge_all')) {
            try {
                wp_edge_cache_purge_all();
                $purge_in_progress = false;
                return ['success' => true, 'output' => 'WP Edge Cache purged directly', 'error' => ''];
            } catch (Exception $e) {
                // Fall through to WP-CLI method
            }
        }

        // 3. Only use WP-CLI if NOT already running in WP-CLI context
        if (defined('WP_CLI') && WP_CLI) {
            // We're inside WP-CLI, so try WordPress native cache clearing instead
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
                $purge_in_progress = false;
                return ['success' => true, 'output' => 'WordPress object cache flushed', 'error' => ''];
            }
            $purge_in_progress = false;
            return ['success' => false, 'output' => '', 'error' => 'Running in WP-CLI context, cannot call WP-CLI recursively'];
        }

        // 4. Fallback to WP Edge Cache via WP-CLI (only when NOT in WP-CLI)
        $wpcli = self::get_wpcli_path();
        if (!$wpcli) {
            $purge_in_progress = false;
            return ['success' => false, 'output' => '', 'error' => 'WP-CLI not found'];
        }

        $wp_path = escapeshellarg(ABSPATH);
        $command = sprintf('%s edge-cache purge --domain=%s 2>&1', escapeshellarg($wpcli), $wp_path);
        $output = shell_exec($command);
        
        $purge_in_progress = false;

        if ($output === null) {
            return ['success' => false, 'output' => '', 'error' => 'WP-CLI command returned null'];
        }

        $success = stripos($output, 'Success') !== false || stripos($output, 'purged') !== false;

        return [
            'success' => $success,
            'output' => trim($output),
            'error' => $success ? '' : 'WP-CLI purge failed: ' . trim($output)
        ];
    }

    private static function get_wpcli_path() {
        if (defined('WPCLI_PATH') && file_exists(WPCLI_PATH) && is_executable(WPCLI_PATH)) {
            return WPCLI_PATH;
        }
        $common_paths = ['/usr/local/bin/wp','/usr/bin/wp','/opt/wp-cli/wp', ABSPATH . 'wp-cli.phar'];
        foreach ($common_paths as $path) {
            if (file_exists($path) && is_executable($path)) return $path;
        }
        $which = @shell_exec('which wp 2>/dev/null');
        if ($which && file_exists(trim($which)) && is_executable(trim($which))) return trim($which);
        return false;
    }

    private static function get_log_file_path() {
        $path = self::LOG_FILE_PATH;
        
        // Expand ~ to home directory if present
        if (strpos($path, '~') === 0) {
            $home = getenv('HOME');
            if (!$home) {
                $home = posix_getpwuid(posix_getuid())['dir'] ?? '';
            }
            $path = $home . substr($path, 1);
        }
        
        return $path;
    }

    private static function log_purge_event($status, $output, $error, $execution_time, $triggered_by = 'wp-cron') {
        $log_file = self::get_log_file_path();
        
        // Ensure directory exists
        $log_dir = dirname($log_file);
        if (!file_exists($log_dir)) {
            if (!@mkdir($log_dir, 0755, true)) {
                error_log('[Cache Purge] Failed to create log directory: ' . $log_dir . ' - Permission denied');
                return;
            }
        }

        // Check if directory is writable
        if (!is_writable($log_dir)) {
            error_log('[Cache Purge] Log directory is not writable: ' . $log_dir . ' - Permission denied');
            return;
        }

        // Create log file if it doesn't exist
        if (!file_exists($log_file)) {
            if (!@touch($log_file)) {
                error_log('[Cache Purge] Failed to create log file: ' . $log_file . ' - Permission denied');
                return;
            }
            @chmod($log_file, 0644);
        }

        // Check if log file is writable
        if (!is_writable($log_file)) {
            error_log('[Cache Purge] Log file is not writable: ' . $log_file . ' - Permission denied');
            return;
        }

        // Get both UTC and WordPress timezone timestamps
        $utc_time = gmdate('Y-m-d H:i:s');
        $wp_time = current_time('mysql');
        $wp_timezone = wp_timezone_string();

        // Format log entry with both times
        $log_entry = sprintf(
            "[UTC: %s | WP: %s (%s)] STATUS: %s | TRIGGERED_BY: %s | EXECUTION_TIME: %.2fs\n",
            $utc_time,
            $wp_time,
            $wp_timezone,
            strtoupper($status),
            $triggered_by,
            $execution_time
        );

        if ($output) {
            $log_entry .= "OUTPUT: " . trim($output) . "\n";
        }

        if ($error) {
            $log_entry .= "ERROR: " . trim($error) . "\n";
        }

        $log_entry .= str_repeat('-', 80) . "\n";

        // Write to log file
        $result = @file_put_contents($log_file, $log_entry, FILE_APPEND | LOCK_EX);
        
        if ($result === false) {
            error_log('[Cache Purge] Failed to write to log file: ' . $log_file . ' - Check file permissions');
        } else {
            error_log(sprintf(
                '[Cache Purge] Event logged: Status=%s, ExecutionTime=%.2fs, TriggeredBy=%s',
                $status,
                $execution_time,
                $triggered_by
            ));
        }
    }

    public static function admin_notices() {
        if (get_option(self::ADMIN_NOTICE_OPTION)) return;
        if (!current_user_can('manage_options')) return;

        $log_file = self::get_log_file_path();
        
        // Get last purge entry from log file
        $last_status = null;
        $last_utc_time = null;
        $last_wp_time = null;
        $last_timezone = null;
        $last_error = null;
        
        if (file_exists($log_file)) {
            $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines) {
                // Read last entry (working backwards)
                $entry_lines = [];
                for ($i = count($lines) - 1; $i >= 0; $i--) {
                    $entry_lines[] = $lines[$i];
                    // Stop at separator line (start of entry)
                    if (strpos($lines[$i], '----') === 0) {
                        break;
                    }
                }
                
                // Parse entry
                $entry_lines = array_reverse($entry_lines);
                foreach ($entry_lines as $line) {
                    if (preg_match('/\[UTC:\s*(.*?)\s*\|\s*WP:\s*(.*?)\s*\((.*?)\)\].*STATUS:\s*(\w+)/', $line, $matches)) {
                        $last_utc_time = $matches[1];
                        $last_wp_time = $matches[2];
                        $last_timezone = $matches[3];
                        $last_status = strtolower($matches[4]);
                    }
                    if (strpos($line, 'ERROR:') === 0) {
                        $last_error = trim(substr($line, 6));
                    }
                }
            }
        }
        
        // Get configured purge times for display
        $purge_times_display = array_map(function($hour) {
            return str_pad($hour, 2, '0', STR_PAD_LEFT) . ':00 UTC';
        }, self::PURGE_TIMES);
        $times_text = implode(', ', $purge_times_display);
        ?>
        <div class="notice notice-warning is-dismissible" id="cache-purge-notice">
            <h3>⚠️ Daily Cache Purge Active</h3>
            <p>This site automatically purges cache daily at: <strong><?php echo esc_html($times_text); ?></strong></p>
            <p>Log file: <code><?php echo esc_html($log_file); ?></code></p>
            <?php if ($last_status): ?>
                <p><strong>Last Purge:</strong><br>
                    UTC: <?php echo esc_html($last_utc_time); ?><br>
                    <?php echo esc_html($last_timezone); ?>: <?php echo esc_html($last_wp_time); ?><br>
                    Status: <span style="color: <?php echo $last_status==='success'?'green':'red'; ?>;">
                        <?php echo esc_html(strtoupper($last_status)); ?>
                    </span>
                    <?php if ($last_status==='failure' && $last_error): ?>
                        <br><span style="color:red;">Error: <?php echo esc_html($last_error); ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
        <script>
        jQuery(document).ready(function($){
            $('#cache-purge-notice').on('click','.notice-dismiss',function(){
                $.post(ajaxurl,{action:'dismiss_cache_purge_notice'});
            });
        });
        </script>
        <?php
    }

    public static function dismiss_admin_notice() {
        update_option(self::ADMIN_NOTICE_OPTION, true, false);
        wp_die();
    }
}

Daily_Cache_Purge::init();
