<?php

class Plugins
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_required_plugins_page']);
        add_action('admin_notices', [$this, 'required_plugins_notice'], 99999999);
        add_action('admin_post_est_install_plugins', [$this, 'install_required_plugins']);
    }

    /**
     * 🔥 Menu page
     */
    public function register_required_plugins_page()
    {
        add_menu_page(
            'Required Plugins',
            'Required Plugins',
            'manage_options',
            'est-required-plugins',
            [$this, 'required_plugins_page'],
            'dashicons-admin-plugins',
            3
        );
    }

    /**
     * 🔥 Page UI
     */
    public function required_plugins_page()
    {
        $plugins = $this->get_missing_plugins();

        $need_action = array_filter($plugins, function ($p) {
            return $p['status'] !== 'active';
        });
?>
        <div class="wrap">
            <h1>Required Plugins</h1>
            <p>Please install and activate required plugins:</p>

            <ul>
                <?php foreach ($plugins as $plugin) : ?>
                    <li>
                        <?php
                        echo $plugin['status'] === 'active' ? '✅' : '❌';
                        echo ' ' . esc_html($plugin['name']) . ' (' . $plugin['status'] . ')';
                        ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <?php if (!empty($need_action)) : ?>
                <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=est_install_plugins'), 'est_install_plugins'); ?>" class="button button-primary">
                    Install & Activate Plugins
                </a>
            <?php else: ?>
                <a href="<?php echo admin_url('tools.php?page=est-maintenance'); ?>" class="button button-primary">
                    Settings
                </a>
                <script>
                    setTimeout(() => {
                        window.location.href = "<?php echo admin_url('tools.php?page=est-maintenance'); ?>";
                    }, 0);
                </script>
            <?php endif; ?>

            <?php if (isset($_GET['installed'])) : ?>
                <div class="notice notice-success">
                    <p>Plugins installed & activated successfully!</p>
                </div>
            <?php endif; ?>
        </div>
<?php
    }

    /**
     * 🔥 Required plugins list
     */
    private function get_required_plugins()
    {
        return [
            'wp-mail-smtp/wp_mail_smtp.php' => 'WP Mail SMTP',
            // 'wp-crontrol/wp-crontrol.php'   => 'WP Crontrol',
            'wordfence/wordfence.php'       => 'Wordfence Security',
        ];
    }

    /**
     * 🔥 Check status
     */
    public function get_missing_plugins()
    {
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $required = $this->get_required_plugins();
        $result = [];

        foreach ($required as $plugin => $name) {

            $path = WP_PLUGIN_DIR . '/' . $plugin;

            if (!file_exists($path)) {
                $status = 'not_installed';
            } elseif (!is_plugin_active($plugin)) {
                $status = 'inactive';
            } else {
                $status = 'active';
            }

            $result[$plugin] = [
                'status' => $status,
                'name'   => $name,
            ];
        }

        return $result;
    }

    /**
     *  Admin notice
     */
    public function required_plugins_notice()
    {
        if (!current_user_can('install_plugins')) {
            return;
        }

        $plugins = $this->get_missing_plugins();

        $need = array_filter($plugins, function ($p) {
            return $p['status'] !== 'active';
        });

        if (empty($need)) return;

        echo '<div class="notice notice-warning"><p><strong>Required plugins missing:</strong></p><ul>';

        foreach ($need as $plugin_file => $data) {

            $slug = dirname($plugin_file);

            echo '<li>' . esc_html($data['name']) . ' - ';

            if ($data['status'] === 'not_installed') {

                $url = wp_nonce_url(
                    admin_url('update.php?action=install-plugin&plugin=' . $slug),
                    'install-plugin_' . $slug
                );

                echo '<a href="' . esc_url($url) . '" class="button button-primary button-small">Install</a>';
            } elseif ($data['status'] === 'inactive') {

                $url = wp_nonce_url(
                    admin_url('plugins.php?action=activate&plugin=' . urlencode($plugin_file)),
                    'activate-plugin_' . $plugin_file
                );

                echo '<a href="' . esc_url($url) . '" class="button button-primary button-small">Activate</a>';
            }

            echo '</li>';
        }

        echo '</ul></div>';
    }

    /**
     * 🚀 Install + Activate
     */
    public function install_required_plugins()
    {
        if (!current_user_can('install_plugins')) {
            wp_die('Permission denied');
        }

        check_admin_referer('est_install_plugins');

        include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        include_once ABSPATH . 'wp-admin/includes/plugin.php';

        $plugins = $this->get_missing_plugins();

        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());

        foreach ($plugins as $plugin_file => $data) {

            $slug = dirname($plugin_file);

            // install nếu chưa có
            if ($data['status'] === 'not_installed') {

                $api = plugins_api('plugin_information', [
                    'slug' => $slug
                ]);

                if (!is_wp_error($api)) {
                    $upgrader->install($api->download_link);
                }
            }

            // activate nếu chưa active
            if ($data['status'] !== 'active') {
                if (!is_plugin_active($plugin_file)) {
                    activate_plugin($plugin_file);
                }
            }
        }

        wp_safe_redirect(admin_url('admin.php?page=est-required-plugins&installed=1'));
        exit;
    }
}

// 🔥 init class
new Plugins();
