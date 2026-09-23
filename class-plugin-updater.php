<?php

if (!defined('ABSPATH')) exit;

class Lp_Report_Updater
{

    public $plugin_file;
    public $plugin_basename;
    public $update_url;
    public $current_version;
    public $slug;
    public $checked = 0;

    public function __construct($plugin_file)
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $this->plugin_file     = $plugin_file;
        $this->plugin_basename = plugin_basename($this->plugin_file);
        $this->update_url      = 'https://raw.githubusercontent.com/DevelopTeamWD/report/refs/heads/main/update-info.json';

        $plugin_data = get_plugin_data($plugin_file);
        $this->current_version = $plugin_data['Version'];
        $this->slug = dirname($this->plugin_basename);

        // Hook update
        add_filter('pre_set_site_transient_update_plugins', array($this, 'modify_plugins_transient'));

        // Popup info
        add_filter('plugins_api', [$this, 'plugin_info'], 10, 3);
    }

    /** CHECK UPDATE */
    public function modify_plugins_transient($transient)
    {

        if (empty($transient->checked)) {
            return $transient;
        }

        $response = wp_remote_get($this->update_url, [
            'timeout' => 15,
            'headers' => ['Accept' => 'application/json']
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return $transient;
        }

        $json = json_decode(wp_remote_retrieve_body($response));

        if (!$json || empty($json->version) || empty($json->download_url) || !$this->is_allowed_package_url($json->download_url)) {
            return $transient;
        }

        if (version_compare($this->current_version, $json->version, '<')) {
            $transient->response[$this->plugin_basename] = (object) [
                'slug'        => $this->slug,
                'plugin'      => $this->plugin_basename,
                'new_version' => $json->version,
                'package'     => $json->download_url,
                'url'         => $json->homepage ?? $json->url ?? '',
                'tested'      => '6.7',
                'requires'    => '5.0'
            ];
        }

        return $transient;
    }

    /** PLUGIN INFO POPUP */
    public function plugin_info($res, $action, $args)
    {
        if ($action !== 'plugin_information' || !is_object($args)) return $res;
        if (($args->slug ?? '') !== $this->slug) return $res;

        $response = wp_remote_get($this->update_url);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return $res;

        $json = json_decode(wp_remote_retrieve_body($response));

        if (!$json || empty($json->name) || empty($json->version)) return $res;

        return (object)[
            'name'          => $json->name,
            'slug'          => $this->slug,
            'version'       => $json->version,
            'download_link' => !empty($json->download_url) && $this->is_allowed_package_url($json->download_url) ? $json->download_url : '',
            'homepage'      => $json->homepage ?? $json->url ?? '',
            'sections'      => [
                'description' => $json->sections->description ?? 'No description',
                'changelog' => $json->sections->changelog ?? 'No changelog'
            ],
            'author' => $json->author ?? '',
            'tested' => $json->tested ?? '',
            'requires' => $json->requires ?? '',
        ];
    }

    private function is_allowed_package_url($url)
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            return false;
        }

        $allowed_hosts = apply_filters('est_updater_allowed_hosts', ['github.com']);
        return is_array($allowed_hosts) && in_array(strtolower($parts['host'] ?? ''), $allowed_hosts, true);
    }
}
