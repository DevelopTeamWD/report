<?php

/**
 * Get scheduled
 */
function est_get_schedule()
{
    $year = date('Y');
    $today = date('Y-m-d');
    $options = get_option('maintenance_options', []);
    $today = isset($options['test']) && !empty($options['test']) ? $options['test'] : $today;

    $start = new DateTime("$year-01-01");
    $end   = new DateTime("$year-12-31");

    if ($start->format('N') != 4) {
        $start->modify('next thursday');
    }

    $interval = new DateInterval('P1W');
    $period   = new DatePeriod($start, $interval, $end);

    $result = [];

    foreach ($period as $date) {
        $current = clone $date;
        $nextWeek = (clone $date)->modify('+7 days');
        $type = 'weekly';

        if ($current->format('m') !== $nextWeek->format('m')) {
            $type = 'monthly';
        }

        $result[$current->format('Y-m-d')] = [
            'date' => $current->format('Y-m-d'),
            'type' => $type,
            'week' => $current->format('W'),
            'month' => $current->format('m'),
        ];
    }

    if (array_key_exists($today, $result)) {
        return $result[$today];
    }

    return [];
}

/**
 * Remove unnecessary files from the WordPress installation for security purposes.
 */
function est_remove_files()
{
    $files_to_remove = [
        ABSPATH . 'readme.html',
        ABSPATH . 'license.txt',
        ABSPATH . 'phpinfo.php',
        ABSPATH . 'wp-config-sample.php',
        ABSPATH . 'error_log',
        ABSPATH . '/wp-content/debug.log',
    ];

    foreach ($files_to_remove as $file) {
        if (file_exists($file)) {
            @unlink($file);

            est_add_log($file . ' file deleted', 'success');
        }
    }
}

/**
 * Disable auto update plugins
 */
function est_disable_auto_update_plugins($update, $item)
{
    return false;
}
add_filter('auto_update_plugin', 'est_disable_auto_update_plugins', 10, 2);

/**
 * Delete backup file
 */
function est_delete_backup($backup_id)
{
    global $wpdb;
    $table = $wpdb->prefix . 'maintenance_backups';

    $backup = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $backup_id),
        ARRAY_A
    );

    if (!$backup) {
        est_add_log('Backup not found', 'error');

        return [
            'success' => false,
            'message' => 'Backup not found'
        ];
    }

    if (file_exists($backup['file_path'])) {
        unlink($backup['file_path']);
    }

    $wpdb->delete($table, ['id' => $backup_id]);

    est_add_log('Backup deleted successfully', 'success');

    return [
        'success' => true,
        'message' => 'Backup deleted successfully'
    ];
}

/**
 * Restore WordPress backup
 * - Extract source files
 * - Restore database
 */
function est_restore_backup($backup_id)
{
    global $wpdb;

    // ===== BACKUP TABLE =====
    $table = $wpdb->prefix . 'maintenance_backups';

    // ===== GET BACKUP INFO =====
    $backup = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $table WHERE id = %d", $backup_id),
        ARRAY_A
    );

    if (!$backup) {
        est_add_log('Backup record not found', 'error');

        return [
            'success' => false,
            'message' => '- Backup record not found'
        ];
    }

    $backup_file = $backup['file_path'];

    if (!file_exists($backup_file)) {
        est_add_log('Backup file not found', 'error');

        return [
            'success' => false,
            'message' => '- Backup file not found'
        ];
    }

    if (!class_exists('ZipArchive')) {
        est_add_log('ZipArchive not installed', 'error');

        return [
            'success' => false,
            'message' => '- ZipArchive not installed'
        ];
    }

    $zip = new ZipArchive();

    if ($zip->open($backup_file) !== true) {
        est_add_log('Cannot open backup file', 'error');

        return [
            'success' => false,
            'message' => '- Cannot open backup file'
        ];
    }

    error_log('>>> Start restore');

    $sql_file = null;

    // ===== EXTRACT SOURCE =====
    for ($i = 0; $i < $zip->numFiles; $i++) {

        $entry = $zip->getNameIndex($i);

        // detect database file
        if (preg_match('/\.sql$/', $entry)) {
            $sql_file = $entry;
            continue;
        }

        $dest = ABSPATH . $entry;

        // create directory
        if (substr($entry, -1) === '/') {

            if (!file_exists($dest)) {
                mkdir($dest, 0755, true);
            }

            continue;
        }

        $dir = dirname($dest);

        if (!file_exists($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = $zip->getFromIndex($i);

        if ($content !== false) {
            file_put_contents($dest, $content);
        }
    }

    error_log('>>> Source restored');

    if (!$sql_file) {

        $zip->close();

        est_add_log('SQL file not found in backup', 'error');

        return [
            'success' => false,
            'message' => '- SQL file not found in backup'
        ];
    }

    // ===== EXTRACT SQL FILE =====
    $tmp_sql = WP_CONTENT_DIR . '/backups/tmp_restore.sql';

    file_put_contents(
        $tmp_sql,
        $zip->getFromName($sql_file)
    );

    $zip->close();

    error_log('>>> SQL extracted');

    // ===== RESTORE DATABASE =====
    $cmd = sprintf(
        'mysql --user=%s --password=%s --host=%s %s < %s',
        escapeshellarg(DB_USER),
        escapeshellarg(DB_PASSWORD),
        escapeshellarg(DB_HOST),
        escapeshellarg(DB_NAME),
        escapeshellarg($tmp_sql)
    );

    exec($cmd, $out, $result);

    unlink($tmp_sql);

    if ($result !== 0) {
        est_add_log('Database restore failed', 'error');

        return [
            'success' => false,
            'message' => '- Database restore failed'
        ];
    }

    if (file_exists($backup_file)) {
        unlink($backup_file);
    }

    est_add_log('Restore completed successfully', 'success');

    return [
        'success' => true,
        'message' => '- Restore completed successfully'
    ];
}

/**
 * Test mail
 */
function est_send_test_mail($to, $subject, $message)
{

    if (!function_exists('is_plugin_active')) {
        include_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }

    $form_plugins = [
        'contact-form-7/wp-contact-form-7.php' => 'Contact Form 7',
        'woocommerce/woocommerce.php'          => 'WooCommerce',
        'wpforms-lite/wpforms.php'             => 'WPForms',
        'ninja-forms/ninja-forms.php'          => 'Ninja Forms',
    ];

    $active_forms = [];

    foreach ($form_plugins as $plugin => $name) {
        if (is_plugin_active($plugin)) {
            $active_forms[] = $name;
        }
    }

    if (empty($active_forms)) {
        return [
            'status' => true,
            'message' => "- No form plugin detected (CF7, WPForms, Woo...)"
        ];
    }

    if (!is_email($to)) {
        return [
            'status' => false,
            'message' => "- Invalid email address"
        ];
    }


    global $phpmailer;

    $headers = ['Content-Type: text/html; charset=UTF-8'];
    $subject .= " Site: " . home_url('/');

    $sent = wp_mail($to, $subject, $message, $headers);

    if ($sent) {
        return [
            'status' => true,
            'message' => "- Email sent successfully"
        ];
    }

    $error_message = 'Unknown error';
    if (isset($phpmailer) && !empty($phpmailer->ErrorInfo)) {
        $error_message = $phpmailer->ErrorInfo;
    }

    return [
        'status' => false,
        'message' => "- Email send failed: {$error_message}"
    ];
}

/**
 * Export sql
 */
function export_sql($file_path)
{
    global $wpdb;

    $file_path = explode('/', $file_path);
    $filename = end($file_path);

    // Lấy danh sách tất cả bảng
    $tables = $wpdb->get_col("SHOW TABLES");

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    foreach ($tables as $table) {
        // --- Cấu trúc bảng ---
        $create_table_query = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
        echo "DROP TABLE IF EXISTS `$table`;\n";
        echo $create_table_query[1] . ";\n\n";

        // --- Dữ liệu ---
        $rows = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);
        if (!empty($rows)) {
            foreach ($rows as $row) {
                $values = array_map(function ($value) {
                    if (is_null($value)) {
                        return "NULL";
                    }

                    return "'" . str_replace("'", "''", $value) . "'";
                }, array_values($row));
                $values = implode(", ", $values);
                echo "INSERT INTO `$table` VALUES ($values);\n";
            }
        }

        echo "\n\n";
    }
}

/**
 * Backup Source
 */
function est_backup_site()
{

    set_time_limit(0);
    ignore_user_abort(true);
    ini_set('memory_limit', '1024M');

    // ===== 1️⃣ CREATE BACKUP DIR =====
    $backup_dir = WP_CONTENT_DIR . '/backups';

    if (!file_exists($backup_dir)) {
        mkdir($backup_dir, 0755, true);
    }

    if (!is_writable($backup_dir)) {
        error_log('Backup directory not writable');
        return [
            'status' => false,
            'message' => '🚨 Backup directory not writable'
        ];
    }

    $time = date('Ymd_His');
    $db_file  = "{$backup_dir}/db_{$time}.sql";
    $zip_file = "{$backup_dir}/backup_{$time}.zip";

    // ===== 2️⃣ EXPORT DATABASE =====
    try {
        // Capture output của hàm export_sql
        ob_start();
        export_sql($db_file);
        $sql_content = ob_get_clean();

        // Ghi nội dung SQL vào file
        if (empty($sql_content)) {
            return [
                'status' => false,
                'message' => '🚨 Database export failed - empty content'
            ];
        }

        if (file_put_contents($db_file, $sql_content) === false) {
            return [
                'status' => false,
                'message' => '🚨 Failed to write database file'
            ];
        }
    } catch (Exception $e) {
        return [
            'status' => false,
            'message' => '🚨 Database export error: ' . $e->getMessage()
        ];
    }

    if (!class_exists('ZipArchive')) {
        return [
            'status' => false,
            'message' => 'ZipArchive not installed'
        ];
    }

    // ===== 2️⃣ ZIP SOURCE CODE + DB =====
    $zip = new ZipArchive();
    if ($zip->open($zip_file, ZipArchive::CREATE) !== true) {
        return [
            'status' => false,
            'message' => " 🚨 Cannot create zip file"
        ];
    }

    // Add WordPress files
    // ===== 4️⃣ ZIP FILES =====
    $root = rtrim(ABSPATH, '/');

    $options = get_option('maintenance_options', []);
    $exclude_from_options = isset($options['explode']) ? trim($options['explode']) : '';

    // Parse exclude patterns từ textarea (mỗi dòng 1 pattern)
    $custom_excludes = [];
    if (!empty($exclude_from_options)) {
        $lines = explode("\n", $exclude_from_options);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!empty($line)) {
                $custom_excludes[] = $line;
            }
        }
    }

    $default_excludes = [
        // System & Cache
        '/wp-content/cache',
        '/wp-content/backups',
        '/wp-content/uploads',
        '/node_modules',
        '/.git',
        '/.svn',
        '/vendor',
        '/tmp',
        '/temp',
        '/logs',
        '/storage',

        // File patterns (regex)
        '/\.log$/',
        '/\.tmp$/',
        '/\.cache$/',
        '/\.sql$/',
        '/\.DS_Store$/',
        '/composer\.json$/',
        '/composer\.lock$/',
        '/package-lock\.json$/',
        '/yarn\.lock$/',
    ];

    // Merge default + custom từ options
    $all_excludes = array_merge($default_excludes, $custom_excludes);

    $directory = new RecursiveDirectoryIterator(
        $root,
        RecursiveDirectoryIterator::SKIP_DOTS
    );

    $files = new RecursiveIteratorIterator(
        $directory,
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $file) {
        $file_path = $file->getRealPath();
        $relative  = str_replace($root, '', $file_path);

        // // Bỏ cache & backup cũ
        // if (strpos($relative, 'wp-content/cache') !== false) continue;
        // if (strpos($relative, 'wp-content/backups') !== false) continue;
        // if (strpos($relative, 'wp-content/uploads') === 0) continue;
        // if (strpos($relative, 'node_modules') !== false) continue;
        // if (strpos($relative, '.git') !== false) continue;
        // if (preg_match('#/(debug\.log|error_log|.*\.log)$#i', $relative)) continue;

        // if ($file->isDir()) {
        //     $zip->addEmptyDir($relative);
        // } else {
        //     $zip->addFile($file_path, $relative);
        // }

        // Normalize path
        $relative = str_replace('\\', '/', $relative);

        // Kiểm tra loại trừ
        $exclude = false;

        foreach ($all_excludes as $pattern) {
            // Nếu pattern bắt đầu và kết thúc bằng / => regex
            if (preg_match('#^/.*/$#', $pattern)) {
                if (preg_match($pattern, $relative)) {
                    $exclude = true;
                    break;
                }
            }
            // Nếu pattern có dấu * (wildcard)
            else if (strpos($pattern, '*') !== false) {
                $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';
                if (preg_match($regex, $relative)) {
                    $exclude = true;
                    break;
                }
            }
            // Path cụ thể
            else {
                // Kiểm tra exact match hoặc bắt đầu bằng pattern
                if (strpos($relative, $pattern) === 0) {
                    $exclude = true;
                    break;
                }
            }
        }

        if ($exclude) {
            continue;
        }

        if ($file->isDir()) {
            $zip->addEmptyDir($relative);
        } else {
            $zip->addFile($file_path, $relative);
        }
    }

    // Add DB file
    $zip->addFile($db_file, basename($db_file));
    $zip->close();

    // ===== DELETE SQL FILE =====
    unlink($db_file);

    est_log_backup(
        $zip_file,
        basename($zip_file),
        'full',
        'success'
    );

    // ===== RETURN RESULT =====
    return [
        'status'   => true,
        'message'   => "- Backup completed",
        'file'      => $zip_file,
        'size_mb'   => round(filesize($zip_file) / 1024 / 1024, 2),
        'created_at' => current_time('mysql')
    ];
}

/**
 * Check & update core wordpress
 */
function est_update_wordpress_core()
{
    require_once ABSPATH . 'wp-admin/includes/update.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/misc.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

    global $wp_version;
    $current_version = $wp_version;

    // Check updater lock
    $lock = get_option('core_updater.lock');

    if ($lock && (time() - (int) $lock) < HOUR_IN_SECONDS) {
        return [
            'status' => true,
            'message' => "- Another WordPress update is currently in progress"
        ];
    }

    // Force unlock (safe if you control flow)
    delete_option('core_updater.lock');

    wp_version_check();
    $updates = get_site_transient('update_core');

    if (empty($updates->updates)) {
        return [
            'status' => true,
            'message' => "- No update available (current version: {$current_version})"
        ];
    }

    $update = null;
    foreach ($updates->updates as $u) {
        if ($u->response === 'upgrade') {
            $update = $u;
            break;
        }
    }

    if (!$update) {
        return [
            'status' => true,
            'message' => "- No update available (current version: {$current_version})"
        ];
    }

    // Perform update
    $upgrader = new Core_Upgrader();
    $result   = $upgrader->upgrade($update);

    if (is_wp_error($result)) {
        return [
            'status' => false,
            'message' => "- WordPress update failed: " . $result->get_error_message()
        ];
    }

    // Reload version after update
    wp_cache_flush();
    require ABSPATH . WPINC . '/version.php';
    global $wp_version;

    return [
        'status' => true,
        'message' => "- Updated {$current_version} → {$wp_version}"
    ];
}

/**
 * Get plugins
 */
function est_get_plugin_update_snapshot()
{
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/update.php';

    wp_update_plugins();

    $plugins = get_plugins();
    $updates = get_site_transient('update_plugins');

    if (empty($updates->response)) {
        return [];
    }

    $snapshot = [];

    foreach ($updates->response as $file => $info) {
        $snapshot[$file] = [
            'file'    => $file,
            'slug'    => $info->slug,
            'package' => $info->package,
            'name'    => $plugins[$file]['Name'] ?? $file,
            'from'    => $plugins[$file]['Version'] ?? '',
            'to'      => $info->new_version ?? '',
            'source'  => 'wporg',
        ];
    }

    return $snapshot;
}

/**
 * Update plugins
 */
function est_update_plugins_from_snapshot()
{
    include_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';

    $all_plugins = get_plugins();
    $active_plugins = get_option('active_plugins', []);
    $inactive_plugins = [];
    $message = "\n";
    $message_after = "Inactive plugins:\n";

    foreach ($all_plugins as $plugin_path => $plugin_data) {
        if (!in_array($plugin_path, $active_plugins, true)) {
            $inactive_plugins[$plugin_path] = $plugin_data['Name'];
            $message_after .= " {$plugin_data['Name']}\n";
        }
    }

    // Lấy snapshot plugin cần update
    $snapshot = est_get_plugin_update_snapshot();

    // Kiểm tra nếu không có plugin nào cần update thì return ngay
    if (empty($snapshot)) {

        est_add_log("No plugin needs update \n {$message_after}", 'error');

        return [
            'status' => true,
            'message' => "- No plugin needs update \n {$message_after}",
        ];
    }

    // Khởi tạo WP Filesystem
    WP_Filesystem();

    global $wp_filesystem;

    $results = [];

    foreach ($snapshot as $plugin) {

        $success = false;

        $plugin_file = $plugin['file'];

        // Nếu là akismet thì xóa luôn
        if ($plugin_file === 'akismet/akismet.php') {

            // Deactivate nếu đang active
            if (is_plugin_active($plugin_file)) {
                deactivate_plugins($plugin_file);
            }

            // Xóa folder plugin
            $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin_file);
            if ($wp_filesystem->exists($plugin_dir)) {
                $wp_filesystem->delete($plugin_dir, true);
            }

            continue;
        }

        // STEP 1: Download zip
        $tmp = download_url($plugin['package']);
        error_log("Downloaded: " . print_r($tmp, true));
        if (is_wp_error($tmp)) {

            est_add_log($plugin['name'] . ' update failed', 'error');

            $results[] = [
                'name'   => $plugin['name'],
                'from'   => $plugin['from'],
                'to'     => $plugin['to'],
                'status' => 'DOWNLOAD_FAIL',
            ];
            continue;
        }

        // STEP 2: Remove old plugin
        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname($plugin['file']);
        if ($wp_filesystem->exists($plugin_dir)) {
            $wp_filesystem->delete($plugin_dir, true);
        }

        // 3️⃣ Extract zip
        $unzipped = unzip_file($tmp, WP_PLUGIN_DIR);
        @unlink($tmp);

        error_log("unzipped: " . print_r($unzipped, true));

        if (is_wp_error($unzipped)) {
            $results[] = [
                'name'   => $plugin['name'],
                'from'   => $plugin['from'],
                'to'     => $plugin['to'],
                'status' => 'UNZIP_FAIL',
            ];
            continue;
        }

        // 4️⃣ Activate
        if (!is_plugin_active($plugin['file'])) {
            activate_plugin($plugin['file']);
        }

        // 5️⃣ Verify version
        wp_clean_plugins_cache(true);
        $plugins_after = get_plugins();
        $new_version = $plugins_after[$plugin['file']]['Version'] ?? '';

        $success = $new_version && version_compare($new_version, $plugin['to'], '>=');

        $results[] = [
            'name'   => $plugin['name'],
            'from'   => $plugin['from'],
            'to'     => $plugin['to'],
            'status' => $success ? 'SUCCESS' : 'VERIFY_FAIL',
        ];
    }


    $update_failed = [];
    $total = count($results);

    if ($total > 0) {
        $message .= "Updated: \n";
    }

    $i = 1;

    foreach ($results as $r) {
        if ($r['status'] !== 'SUCCESS') {
            $update_failed[] = $r['name'];
        }
        $newline = ($i < $total - 1) ? "\n" : '';
        $message .= "- {$r['name']}: {$r['from']} → {$r['to']} [{$r['status']}]{$newline}";
        $i++;
    }

    return [
        'status' => !empty($update_failed) ? false : true,
        'message' => $message . "\n {$message_after}"
    ];
}

/**
 * Scan malware
 */
function est_check_security($error = false)
{
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $plugin_file = 'wordfence/wordfence.php';

    $message = '';

    if (!file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {

        $results = [
            'status' => false,
            'message' => "- Wordfence plugin is not installed"
        ];
    } elseif (!is_plugin_active($plugin_file)) {

        $results = [
            'status' => false,
            'message' => "- Wordfence plugin is installed but not activated"
        ];
    } else {

        $issues = new wfIssues();
        $logs = $issues->getIssues();

        foreach ($logs as $items) {
            foreach ($items as $issue) {
                if ($issue['type'] == 'skippedPaths') {
                    continue;
                }
                if (!stripos($issue['shortMsg'], 'debug.log') === false) {
                    continue;
                }
                if (!empty($issue['shortMsg'])) {
                    $message .= "    {$issue['shortMsg']}\n";
                }
            }
        }

        $results = [
            'status' => empty($message) ? true : false,
            'message' => $message ?: "- No security issue detected"
        ];
    }

    est_add_maintenance_step(
        'check_security',
        $results['status'],
        $results['message'],
        'Check security'
    );
}

/**
 * CRUD
 */
function send_lark_post($title, $content_blocks)
{

    $timestamp = time();
    $stringToSign = $timestamp . "\n" . LARK_SECRET;

    // HMAC-SHA256 + base64
    $sign = base64_encode(
        hash_hmac('sha256', $stringToSign, LARK_SECRET, true)
    );

    $data = [
        'timestamp' => (string) $timestamp,
        'sign'      => $sign,
        'msg_type'  => 'post',
        'content'   => [
            'post' => [
                'en_us' => [
                    'title'   => $title,
                    'content' => $content_blocks,
                ],
            ],
        ],
    ];

    $ch = curl_init(LARK_WEBHOOK);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($data, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function est_hanle_job($title, $results = [], $res = null)
{
    $options = get_option('maintenance_options', []);
    $member = esc_attr($options['member'] ?? 'webdev');
    $site = get_bloginfo('name');
    $datetime = current_time('Y-m-d H:i:s');

    $link = home_url('/');

    $current = PHP_VERSION;

    $blocks = [[
        [
            'tag'  => 'text',
            'text' => "$site\n",
        ],
        [
            'tag'  => 'text',
            'text' => "Site: $link \n",
        ],
        [
            'tag'  => 'text',
            'text' => "PHP version: $current \n",
        ],
        [
            'tag'  => 'text',
            'text' => "Member: @$member \n\n",
        ],
    ]];

    $i = 1;
    foreach ($results as $step => $result) {

        $label = $result['title'] ?? $step;

        $message = $result['message'] ?? 'No message';

        $blocks[] = [[
            'tag'  => 'text',
            'text' => "#{$i} {$label}:  \n {$message}\n\n",
        ]];
        $i++;
    }

    // Tính tổng số điểm thành công
    $total_steps = count($results);
    $successful_steps = 0;
    foreach ($results as $result) {
        if (!empty($result['status'])) {
            $successful_steps++;
        }
    }
    $blocks[] = [[
        'tag'  => 'text',
        'text' => "Summary: {$successful_steps} / {$total_steps} steps successful.\n",
    ]];

    if (!empty($res)) send_lark_post("$title \n🕒 $datetime", $blocks);
}


add_filter('manage_maintenance_posts_columns', function ($columns) {
    $columns['steps'] = 'Steps';
    return $columns;
});

add_action('manage_maintenance_posts_custom_column', function ($column, $post_id) {
    if ($column === 'steps') {
        $list = get_post_meta($post_id, '_maintenance_logs', true);
        echo '<ul>';
        if (!empty($list)) {
            foreach ($list as $item) {
                echo "<li>{$item["title"]} <br>  {$item["message"]}</li>";
            }
        }
        echo '</ul>';
    }
}, 10, 2);

function est_report()
{
    $is_scan = est_get_schedule();

    if (!empty($is_scan)) {
        $log = get_option('maintenance_log');
        extract($log);
        est_hanle_job(ucfirst($action) . ' Maintenance', $steps, true);
    }
}
