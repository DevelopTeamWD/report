<?php

add_action('admin_init', function () {
    register_setting(
        'maintenance_group',
        'maintenance_options',
        [
            'type'              => 'array',
            'sanitize_callback' => function ($options) {
                return [
                    'hook'      => sanitize_text_field($options['hook'] ?? ''),
                    'member'    => sanitize_text_field($options['member'] ?? ''),
                    'mail_test' => sanitize_text_field($options['mail_test'] ?? ''),
                    'test'      => sanitize_text_field($options['test'] ?? ''),
                    'time'      => sanitize_text_field($options['time'] ?? ''),
                    'date'      => sanitize_text_field($options['date'] ?? ''),
                    'explode'   => sanitize_textarea_field($options['explode'] ?? ''),
                ];
            },
            'default' => [],
        ]
    );
});

add_action('admin_menu', function () {
    add_submenu_page(
        'tools.php',
        'EST Maintenance',
        'EST Maintenance',
        'manage_options',
        'est-maintenance',
        function () {
            $options = get_option('maintenance_options', []);
            $log = get_option('maintenance_log');
?>
        <div class="wrap">
            <div id="config">
                <h3>Settings</h3>
                <form method="post" action="options.php">
                    <?php settings_fields('maintenance_group'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="member">Mail test</label>
                            </th>
                            <td>
                                <input type="mail" name="maintenance_options[mail_test]" value="<?php echo esc_attr($options['mail_test'] ?? 'webdevdevelopment@enosta.com'); ?>" class="regular-text" />
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="member">Member</label>
                            </th>
                            <td>
                                <input type="text" name="maintenance_options[member]" value="<?php echo esc_attr($options['member'] ?? ''); ?>" class="regular-text" />
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="member">Explode</label>
                            </th>
                            <td>
                                <textarea name="maintenance_options[explode]" class="regular-text" rows="5" placeholder="/backup/"><?php echo esc_attr($options['explode'] ?? ''); ?></textarea>
                                <br>
                                <small>Enter each value on a new line</small>
                                Example: <br>
                                <pre>/backup</pre>

                            </td>
                        </tr>

                        <tr>
                            <th>Time run</th>
                            <td>
                                <input type="date" autocorrect="off" autocapitalize="off" name="maintenance_options[date]" value="<?php echo esc_attr($options['date'] ?? ''); ?>" step="1" placeholder="yyyy-mm-dd" pattern="\d{4}-\d{2}-\d{2}">
                                <input type="time" autocorrect="off" autocapitalize="off" name="maintenance_options[time]" value="<?php echo esc_attr($options['time'] ?? ''); ?>" step="1" placeholder="hh:mm:ss" pattern="\d{2}:\d{2}:\d{2}">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="member">Test today</label>
                            </th>
                            <td>
                                <input type="text" name="maintenance_options[test]" value="<?php echo esc_attr($options['test'] ?? ''); ?>" class="regular-text" />
                            </td>
                        </tr>

                    </table>
                    <input
                        type="hidden" id="hook" name="maintenance_options[hook]" value="<?php echo esc_attr($options['hook'] ?? ''); ?>" class="regular-text" />
                    <?php submit_button(); ?>
                </form>

            </div>
            <div id="backup" style="background-color: #fff;padding: 12px;border-radius: 4px; margin-bottom: 1rem;">
                <?php
                if (!empty($log)) {
                    echo '<h3>' . ucfirst($log['action']) . ' Maintenance</h3>';
                    echo '<p>Last run: ' . esc_html($log['last_run']) . '</p>';
                    if (!empty($log['steps'])) {
                        foreach ($log['steps'] as $index => $step) {
                            echo sprintf(
                                '<p>#%d %s <br> %s</p>',
                                $index + 1,
                                esc_html($step['title']),
                                nl2br(esc_html($step['message']))
                            );
                        }
                    }
                }
                ?>
            </div>
            <div id="backup" style="background-color: #fff;padding: 12px;border-radius: 4px;">
                <h3>Backups</h3>
                <?php
                global $wpdb;
                $limit = 200;
                $data = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}maintenance_backups ORDER BY created_at DESC LIMIT %d",
                        $limit
                    ),
                    ARRAY_A
                );

                ?>
                <table class="widefat fixed striped">
                    <thead>
                        <tr>
                            <th width="5%">#</th>
                            <th>File name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Created at</th>
                            <th width="15%">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if (empty($data)) {
                        ?>
                            <tr>
                                <td colspan="6" style="text-align: center;">
                                    <span>No backups found.</span>
                                </td>
                            </tr>
                        <?php
                        } else {
                        ?>
                            <?php foreach ($data as $index => $row): ?>
                                <tr>
                                    <td><?php echo esc_html($index + 1); ?></td>

                                    <td>
                                        <?php echo esc_html($row['file_name']); ?>
                                    </td>

                                    <td>
                                        <?php echo esc_html(ucfirst($row['type'])); ?>
                                    </td>

                                    <td>
                                        <?php if ($row['status'] === 'success'): ?>
                                            <span style="color:green;font-weight:600;">✅ Success</span>
                                        <?php else: ?>
                                            <span style="color:red;font-weight:600;">❌ Failed</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php echo esc_html(
                                            date_i18n(
                                                'Y-m-d H:i:s',
                                                strtotime($row['created_at'])
                                            )
                                        ); ?>
                                    </td>

                                    <td>
                                        <?php if ($row['status'] === 'success' && !empty($row['file_path']) && file_exists($row['file_path'])): ?>
                                            <a class="button"
                                                href="<?php echo esc_url(content_url(str_replace(WP_CONTENT_DIR, '', $row['file_path']))); ?>"
                                                target="_blank" title="Download">
                                                ⬇️
                                            </a>
                                        <?php else: ?>
                                            <span style="color:#999;">🚫</span>
                                        <?php endif; ?>
                                        <button class="button btn-restore" title="Restore" data-id="<?php echo $row['id']; ?>" data-action="restore">♻️</button>
                                        <button class="button btn-delete" title="Delete" data-id="<?php echo $row['id']; ?>" data-action="delete">🗑️</button>
                                    </td>
                                </tr>
                        <?php endforeach;
                        } ?>
                    </tbody>
                </table>
            </div>
        </div>
        <script>
            jQuery(document).ready(function($) {

                $(document).on('click', '.btn-restore, .btn-delete', function(e) {
                    e.preventDefault();

                    const btn = $(this);
                    const backupId = btn.data('id');
                    const actionType = btn.data('action');

                    if (!backupId || !actionType) return;

                    if (actionType === 'delete') {
                        if (!confirm('Are you sure you want to delete this backup?')) {
                            return;
                        }
                    }

                    if (actionType === 'restore') {
                        if (!confirm('Restore this backup? The site may be overwritten.')) {
                            return;
                        }
                    }

                    btn.prop('disabled', true).text('⏳');

                    $.ajax({
                        url: ajaxurl, // admin-ajax.php
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'est_backup_action',
                            backup_id: backupId,
                            backup_action: actionType
                        },
                        success: function(res) {
                            if (res.success && actionType === 'delete') {
                                btn.closest('tr').fadeOut(300, function() {
                                    $(this).remove();
                                });
                            }

                            if (res.success && actionType === 'restore') {
                                window.location.reload();
                            }
                        },
                        error: function() {
                            alert('Something went wrong');
                        },
                        complete: function() {
                            btn.prop('disabled', false).text(
                                actionType === 'delete' ? '🗑️' : '♻️'
                            );
                        }
                    });
                });

            });
        </script>
<?php
        }
    );
});
