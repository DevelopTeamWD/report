<?php


/**
 * Plugin Name: WP Report Web
 * Description: 
 * Version: 1.0.0
 * Author: Long Huynh
 */

if (!defined('ABSPATH')) exit;

define('LARK_WEBHOOK', 'https://open.larksuite.com/open-apis/bot/v2/hook/26381d2c-0b20-4d0c-aab2-f0db024f2ebf');
define('LARK_SECRET', '');
define('PLUGIN_UPDATE_SECRET', 'CHANGE_ME');
define('EST_WF_SCAN_STALE', 15 * MINUTE_IN_SECONDS);
define('EST_WF_SCAN_TIMEOUT', 2 * HOUR_IN_SECONDS);

require_once plugin_dir_path(__FILE__) . 'class-scan.php';
require_once plugin_dir_path(__FILE__) . 'helper.php';
require_once plugin_dir_path(__FILE__) . 'class-plugins.php';
require_once plugin_dir_path(__FILE__) . 'class-setting.php';
require_once  plugin_dir_path(__FILE__)  . 'class-plugin-updater.php';

/**
 * 🔥 Run when plugin is ACTIVATED
 */
register_activation_hook(__FILE__, 'est_plugin_activate');
function est_plugin_activate()
{

    add_option('est_redirect_install_plugins', true);

    if (get_option('maintenance_log') === false) {
        add_option('maintenance_log', []);
    }
}

/**
 * 🔥 Run when plugin is DEACTIVATED (optional)
 */
register_deactivation_hook(__FILE__, 'est_plugin_deactivate');
function est_plugin_deactivate()
{
    delete_option('maintenance_log');
}

add_action('admin_init', 'est_redirect_after_activation');
function est_redirect_after_activation()
{
    if (!get_option('est_redirect_install_plugins')) {
        return;
    }

    delete_option('est_redirect_install_plugins');

    if (isset($_GET['activate-multi'])) {
        return;
    }

    wp_safe_redirect(admin_url('admin.php?page=est-required-plugins'));
    exit;
}

/**
 * 
 */
register_activation_hook(__FILE__, 'est_create_backup_table');
function est_create_backup_table()
{
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table_backups = $wpdb->prefix . 'maintenance_backups';
    $sql_backups = "CREATE TABLE $table_backups (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        file_path TEXT NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        type VARCHAR(50) DEFAULT 'full',
        status VARCHAR(20) DEFAULT 'success',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";
    dbDelta($sql_backups);

    $table_logs = $wpdb->prefix . 'maintenance_logs';
    $sql_logs = "CREATE TABLE $table_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        action VARCHAR(255) NOT NULL,
        status VARCHAR(20) DEFAULT 'success',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_status (status),
        KEY idx_created_at (created_at)
    ) $charset_collate;";
    dbDelta($sql_logs);
}

function est_add_log($action, $status = 'success')
{
    global $wpdb;

    $table = $wpdb->prefix . 'maintenance_logs';

    $data = array(
        'action' => $action,
        'status' => $status
    );

    return $wpdb->insert($table, $data);
}

function est_get_logs($limit = 50, $status = '')
{
    global $wpdb;

    $table = $wpdb->prefix . 'maintenance_logs';
    $where = '';

    if (!empty($status)) {
        $where = $wpdb->prepare(" WHERE status = %s", $status);
    }

    $sql = "SELECT * FROM $table $where ORDER BY created_at DESC LIMIT %d";
    return $wpdb->get_results($wpdb->prepare($sql, $limit));
}

function est_log_backup($file_path, $file_name, $type = 'full', $status = 'success')
{
    global $wpdb;

    $wpdb->insert(
        $wpdb->prefix . 'maintenance_backups',
        [
            'file_path' => $file_path,
            'file_name' => $file_name,
            'type'      => $type,
            'status'    => $status,
            'created_at' => current_time('mysql'),
        ],
        ['%s', '%s', '%s', '%s', '%s']
    );
}

// Thêm link Settings vào trang plugin
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {

    $settings_link = '<a href="admin.php?page=est-maintenance">Settings</a>';
    array_unshift($links, $settings_link);

    return $links;
});


/**
 * Handle delete backup file or restore site
 */
add_action('wp_ajax_est_backup_action', 'est_backup_action_handler');
function est_backup_action_handler()
{
    if (!current_user_can('manage_options')) {
        wp_send_json([
            'success' => false,
            'message' => 'Permission denied'
        ]);
    }

    $backup_id = intval($_POST['backup_id'] ?? 0);
    $action    = sanitize_text_field($_POST['backup_action'] ?? '');

    if (!$backup_id || !in_array($action, ['restore', 'delete'], true)) {
        wp_send_json([
            'success' => false,
            'message' => 'Invalid request'
        ]);
    }

    if ($action === 'delete') {
        $res = est_delete_backup($backup_id);
    }

    if ($action === 'restore') {
        $res = est_restore_backup($backup_id);
    }

    wp_send_json($res);
}

/**
 * Redirect sang trang Settings sau khi active plugin
 */
add_action('activated_plugin', function ($plugin) {

    if ($plugin === plugin_basename(__FILE__)) {
        if (!isset($_GET['activate-multi'])) {
            wp_safe_redirect(
                admin_url('admin.php?page=est-maintenance')
            );
            exit;
        }
    }
});

function est_get_maintenance_log()
{
    $log = get_option('maintenance_log', []);
    return is_array($log) ? $log : [];
}

function est_add_maintenance_step($step, $status, $message = '', $title = "")
{
    $log = est_get_maintenance_log();

    if (empty($log)) {
        return;
    }

    $log['steps'][] = [
        'step'    => $step,
        'title'   => $title,
        'status'  => (bool) $status,
        'message' => $message,
        'time'    => current_time('mysql'),
    ];

    update_option('maintenance_log', $log, false);
}

require_once plugin_dir_path(__FILE__) . 'class-weekly.php';
require_once plugin_dir_path(__FILE__) . 'class-month.php';
require_once plugin_dir_path(__FILE__) . 'class-middle-year.php';

/**
 * CHECK scan DONE – chạy lại mỗi 10 giây
 */
add_action('est_scan_monitor', 'est_scan_done');
function est_scan_done()
{

    $end_time = get_option('maintenance_time_end');
    if ($end_time) {
        $current_time = current_time('timestamp');
        $end_timestamp = strtotime($end_time);

        error_log($current_time . '-' . $end_timestamp);
        // Nếu quá thời gian cho phép thì phản hồi không cần scan nữa
        if ($current_time > $end_timestamp) {
            wp_clear_scheduled_hook('est_scan_monitor');
            est_check_security(true);
            $is_scan = est_get_schedule();

            // Gửi về lark nếu là weekly
            $type = $is_scan['type'];
            if ($type === 'weekly') {
                est_report();
            }

            // Tiếp tục xử lý cho monthly
            if ($type === 'monthly') {
                $month = new Month();
                $month->run();
            }
            return;
        }
    }

    // scan đang chạy
    if (wfScanner::shared()->isRunning()) {
        error_log('[EST] Scan running – check again in 10s');
        wp_schedule_single_event(time() + 10, 'est_scan_monitor');
        spawn_cron();
        return;
    }

    // ✅ DONE
    error_log('Wordfence scan: DONE');
    wp_clear_scheduled_hook('est_scan_monitor');

    // Get issues
    est_check_security();

    $is_scan = est_get_schedule();

    $type = $is_scan['type'];
    if ($type === 'weekly') {
        est_report();
    }

    if ($type === 'monthly') {
        $month = new Month();
        $month->run();
    }
}

/**
 * Start job
 */
function est_start_scan()
{

    $schedule = est_get_schedule();

    if (empty($schedule)) return;

    wordfence::ajax_killScan_callback();

    update_option('maintenance_log', [
        'action'   => $schedule['type'], // weekly | monthly
        'status'   => 'running',
        'last_run' => current_time('mysql'),
        'steps'    => [],
    ], false);

    $weekly = new Weekly();
    $weekly->run();
}

/**
 * Đọc và parse date + time từ option 'maintenance_options'
 *
 * @return array [year, month, day, hour, minute, second]
 */
function lp_cron_get_datetime_from_options()
{
    $options = get_option('maintenance_options', array());

    $date = isset($options['date']) ? trim((string) $options['date']) : '';
    $time = isset($options['time']) ? trim((string) $options['time']) : '';

    // --- Parse date "Y-m-d" ---
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $date, $dm)) {
        $year  = (int) $dm[1];
        $month = (int) $dm[2];
        $day   = (int) $dm[3];
    } else {
        // Fallback: ngày hôm nay theo giờ VN
        $vn_tz = new DateTimeZone('Asia/Ho_Chi_Minh');
        $now   = new DateTime('now', $vn_tz);
        $year  = (int) $now->format('Y');
        $month = (int) $now->format('n');
        $day   = (int) $now->format('j');
    }

    // --- Parse time "H:i:s" hoặc "H:i" ---
    if (preg_match('/^(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?$/', $time, $tm)) {
        $hour   = (int) $tm[1];
        $minute = (int) $tm[2];
        $second = isset($tm[3]) ? (int) $tm[3] : 0;
    } else {
        $hour   = 0;
        $minute = 0;
        $second = 0;
    }

    // --- Validate ---
    $year   = max(1970, min(2099, $year));
    $month  = max(1,    min(12,   $month));
    $day    = max(1,    min(31,   $day));
    $hour   = max(0,    min(23,   $hour));
    $minute = max(0,    min(59,   $minute));
    $second = max(0,    min(59,   $second));

    return array($year, $month, $day, $hour, $minute, $second);
}


/**
 * Lấy signature hiện tại của option (để phát hiện thay đổi)
 *
 * @return string
 */
function lp_cron_get_option_signature()
{
    $options = get_option('maintenance_options', array());
    $date    = isset($options['date']) ? (string) $options['date'] : '';
    $time    = isset($options['time']) ? (string) $options['time'] : '';
    return $date . '|' . $time;
}


/**
 * Tính timestamp UTC cho lần chạy tiếp theo
 *
 * @return int
 */
function lp_cron_get_next_run_timestamp()
{
    list($year, $month, $day, $hour, $minute, $second) = lp_cron_get_datetime_from_options();

    $vn_tz = new DateTimeZone('Asia/Ho_Chi_Minh');
    $now   = new DateTime('now', $vn_tz);

    // Tạo target = date + time từ option
    $target = new DateTime('now', $vn_tz);
    $target->setDate($year, $month, $day);
    $target->setTime($hour, $minute, $second);

    // Nếu target đã qua → chuyển sang NGÀY MAI, giữ nguyên giờ
    if ($target <= $now) {
        $target = new DateTime('tomorrow', $vn_tz);
        $target->setTime($hour, $minute, $second);
    }

    return $target->getTimestamp();
}


/**
 * =====================================================
 * PHẦN 2: CALLBACK — code chạy khi cron kích hoạt
 * =====================================================
 */

add_action('lp_cron_hook', 'lp_cron_function');

function lp_cron_function()
{
    // --- Lock tránh chạy trùng (WP-Cron có thể trigger nhiều lần) ---
    if (get_transient('lp_cron_running')) {
        error_log('> [CRON] Bỏ qua vì đang có instance khác chạy.');
        return;
    }

    set_transient('lp_cron_running', 1, 5 * MINUTE_IN_SECONDS);

    $vn_tz   = new DateTimeZone('Asia/Ho_Chi_Minh');
    $vn_time = wp_date('Y-m-d H:i:s', time(), $vn_tz);

    error_log('> [CRON] Bắt đầu chạy lúc: ' . $vn_time . ' (giờ VN)');

    lp_cron_update_option_date_to_next_run();

    est_start_scan();

    delete_transient('lp_cron_running');
}


/**
 * Cập nhật maintenance_options['date'] thành ngày chạy kế tiếp
 * (ngày mai theo giờ VN, giữ nguyên 'time')
 *
 * @return void
 */
function lp_cron_update_option_date_to_next_run()
{
    $options = get_option('maintenance_options', array());

    $vn_tz = new DateTimeZone('Asia/Ho_Chi_Minh');

    // Lấy giờ từ option (giữ nguyên)
    $time = isset($options['time']) ? trim((string) $options['time']) : '';

    if (preg_match('/^(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?$/', $time, $tm)) {
        $hour = (int) $tm[1];
        $min  = (int) $tm[2];
        $sec  = isset($tm[3]) ? (int) $tm[3] : 0;

        // Chuẩn hoá lại time thành "H:i:s"
        $options['time'] = sprintf('%02d:%02d:%02d', $hour, $min, $sec);
    } else {
        // Fallback: giờ hiện tại VN
        $now  = new DateTime('now', $vn_tz);
        $hour = (int) $now->format('G');
        $min  = (int) $now->format('i');
        $sec  = (int) $now->format('s');

        $options['time'] = sprintf('%02d:%02d:%02d', $hour, $min, $sec);
    }

    // Ngày mai theo giờ VN
    $tomorrow = new DateTime('tomorrow', $vn_tz);
    $tomorrow->setTime($hour, $min, $sec);

    // Ghi lại date mới (Y-m-d)
    $options['date'] = $tomorrow->format('Y-m-d');

    update_option('maintenance_options', $options);

    error_log('> [CRON] Đã update option date = ' . $options['date']
        . ' time = ' . $options['time']);
}


/**
 * =====================================================
 * PHẦN 3: ĐĂNG KÝ VÀ RESET CRON
 * =====================================================
 */

add_action('init', 'lp_cron_maybe_schedule');

function lp_cron_maybe_schedule()
{
    $current_signature = lp_cron_get_option_signature();
    $stored_signature  = get_option('lp_cron_signature', '');
    $scheduled_ts      = wp_next_scheduled('lp_cron_hook');

    // --- Kiểm tra xem có cần đăng ký lại không ---
    $need_reschedule = false;

    // Trường hợp 1: chưa có cron
    if (! $scheduled_ts) {
        $need_reschedule = true;
    }
    // Trường hợp 2: option thay đổi
    elseif ($current_signature !== $stored_signature) {
        $need_reschedule = true;
    }
    // KHÔNG kiểm tra lệch timestamp nữa:
    // - callback tự update option date sau khi chạy
    // - signature sẽ đổi → init kế tiếp tự reschedule

    if (! $need_reschedule) {
        return;
    }

    // --- Xóa sạch tất cả event cũ của hook này ---
    lp_cron_unschedule_all();

    // --- Đăng ký lại với timestamp mới ---
    $expected_ts = lp_cron_get_next_run_timestamp();

    wp_schedule_event(
        $expected_ts,
        'daily',
        'lp_cron_hook'
    );

    // --- Lưu signature mới ---
    update_option('lp_cron_signature', $current_signature);
}


/**
 * Hủy toàn bộ event của cron hook
 */
function lp_cron_unschedule_all()
{
    $ts = wp_next_scheduled('lp_cron_hook');
    while ($ts) {
        wp_unschedule_event($ts, 'lp_cron_hook');
        $ts = wp_next_scheduled('lp_cron_hook');
    }
}


/**
 * =====================================================
 * PHẦN 4: ADMIN DEBUG — hiển thị trạng thái cron
 * =====================================================
 */

add_action('admin_notices', 'lp_cron_debug_notice');

function lp_cron_debug_notice()
{
    if (! current_user_can('manage_options')) {
        return;
    }

    if (isset($_GET) && isset($_GET['page']) && $_GET['page'] === 'est-maintenance') {
        $ts    = wp_next_scheduled('lp_cron_hook');
        $vn_tz = new DateTimeZone('Asia/Ho_Chi_Minh');

        echo '<div class="notice notice-info"><p>';
        echo '<strong>=== CRON ===</strong><br>';

        if ($ts) {
            echo '<strong>Cron has been registered.:</strong><br>';
            echo '- Run (VN): <strong>'
                . esc_html(wp_date('Y-m-d H:i:s', $ts, $vn_tz)) . '</strong><br>';
            echo '- Remaining time: '
                . esc_html(human_time_diff(time(), $ts)) . '<br>';
        } else {
            echo '<span style="color:red"><strong>Cron is NOT registered.!</strong></span><br>';
        }

        echo '</p></div>';
    }
}

add_action('plugins_loaded', function () {
    new Lp_Report_Updater(__FILE__);
});
