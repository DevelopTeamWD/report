<?php

class Month
{

    function get_permission_octal($path)
    {
        return substr(sprintf('%o', fileperms($path)), -4);
    }

    function check_permission_files()
    {
        $root = ABSPATH;

        $check_targets = [
            $root,
            $root . 'wp-includes',
            $root . '.htaccess',
            $root . 'wp-admin/index.php',
            $root . 'wp-admin/js',
            $root . 'wp-content/themes',
            $root . 'wp-content/plugins',
            $root . 'wp-admin',
            $root . 'wp-content',
            $root . 'wp-config.php',
        ];

        $failed_items = [];
        $message = '';

        foreach ($check_targets as $item) {
            if (!file_exists($item)) continue;


            $is_dir  = is_dir($item);
            $current = $this->get_permission_octal($item);

            // Default recommended
            $recommended = $is_dir ? '0755' : '0644';

            // Special cases
            if (basename($item) === 'wp-config.php') {
                $recommended = '0400';
            }

            if (basename($item) === '.htaccess') {
                $recommended = '0444';
            }

            if ($current !== $recommended) {
                $name = basename($item);

                if ($name === '') {
                    $name = 'root';
                }

                $message .= "- $name permission: $current (recommended: $recommended) \n";

                est_add_log("  $name permission: $current (recommended: $recommended)", 'error');
            }
        }

        if (!empty($message)) {
            est_add_log($message, 'error');

            return [
                'status' => false,
                'message' => $message
            ];
        }

        est_add_log('All permissions are correct', 'success');

        return [
            'status' => true,
            'message' => "- All permissions are correct"
        ];
    }

    function clean_database()
    {
        global $wpdb;

        $errors = [];
        $stats  = [
            'revisions' => 0,
            'spam_comments' => 0,
            'optimized_tables' => 0,
        ];

        // 1. Delete post revisions
        $revisions = $wpdb->query(
            "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'"
        );

        if ($revisions === false) {
            $errors[] = '- Failed to delete post revisions';
        } else {
            $stats['revisions'] = $revisions;
        }

        // 2. Delete spam comments
        $spam = $wpdb->query(
            "DELETE FROM {$wpdb->comments} WHERE comment_approved = 'spam'"
        );

        if ($spam === false) {
            $errors[] = '- Failed to delete spam comments';
        } else {
            $stats['spam_comments'] = $spam;
        }

        // 3. Optimize tables
        $tables = $wpdb->get_col("SHOW TABLES");
        foreach ($tables as $table) {
            $result = $wpdb->query("OPTIMIZE TABLE {$table}");
            if ($result !== false) {
                $stats['optimized_tables']++;
            }
        }

        if (!empty($errors)) {

            est_add_log('Database cleanup completed with errors', 'error');

            return [
                'status'  => false,
                'message' => "- Database cleanup completed with errors",
            ];
        }

        est_add_log('Database cleaned and optimized successfully', 'success');

        return [
            'status'  => true,
            'message' => "- Database cleaned and optimized successfully",
        ];
    }

    function optimize_performance()
    {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $plugin = 'autoptimize/autoptimize.php';

        // 1. Check installed
        $installed_plugins = get_plugins();
        if (!isset($installed_plugins[$plugin])) {
            return [
                'status'  => false,
                'message' => "- Autoptimize plugin is not installed",
            ];
        }

        // 2. Check active
        if (!is_plugin_active($plugin)) {

            $result = activate_plugin($plugin);

            if (is_wp_error($result)) {
                return [
                    'status'  => false,
                    'message' => "- Failed to activate Autoptimize: " . $result->get_error_message(),
                ];
            }
        }

        $configs = [
            'autoptimize_cache_clean' => 0,
            'autoptimize_cache_fallback' => 'on',
            'autoptimize_cache_nogzip' => 'on',
            'autoptimize_cdn_url' => '',
            'autoptimize_css' => 'on',
            'autoptimize_css_aggregate'  => 'on',
            'autoptimize_css_datauris'  => 'on',
            'autoptimize_css_defer'  => '',
            'autoptimize_css_defer_inline'  => '',
            'autoptimize_css_exclude'  => ', admin-bar.min.css, dashicons.min.css, wp-content/cache/, wp-content/uploads/',
            'autoptimize_css_include_inline'  => 'on',
            'autoptimize_css_inline'  => '',
            'autoptimize_css_justhead'  => '',
            'autoptimize_enable_meta_ao_settings'  => 'on',
            'autoptimize_enable_site_config'  => 'on',
            'autoptimize_extra_settings'  => 'a:7:{s:31:"autoptimize_extra_radio_field_4";s:1:"1";s:34:"autoptimize_extra_checkbox_field_1";s:1:"1";s:34:"autoptimize_extra_checkbox_field_0";s:1:"1";s:34:"autoptimize_extra_checkbox_field_8";s:1:"1";s:30:"autoptimize_extra_text_field_2";s:0:"";s:30:"autoptimize_extra_text_field_7";s:0:"";s:30:"autoptimize_extra_text_field_3";s:0:"";}',
            'autoptimize_html'  => 'on',
            'autoptimize_html_keepcomments'  => 'on',
            'autoptimize_html_minify_inline'  => 'on',
            'autoptimize_imgopt_launched'  => 'on',
            'autoptimize_imgopt_settings'  => 'a:6:{s:31:"autoptimize_imgopt_text_field_6";s:0:"";s:33:"autoptimize_imgopt_select_field_2";s:1:"2";s:35:"autoptimize_imgopt_checkbox_field_4";s:1:"0";s:35:"autoptimize_imgopt_checkbox_field_3";s:1:"1";s:31:"autoptimize_imgopt_text_field_5";s:0:"";s:33:"autoptimize_imgopt_number_field_7";s:1:"2";}',
            'autoptimize_installed_before_compatibility'  => 'on',
            'autoptimize_js'  => 'on',
            'autoptimize_js_aggregate'  => 'on',
            'autoptimize_js_defer_inline'  => 'on',
            'autoptimize_js_defer_not_aggregate'  => '',
            'autoptimize_js_exclude'  => ', wp-includes/js/dist/, wp-includes/js/tinymce/, js/jquery/jquery.min.js',
            'autoptimize_js_forcehead'  => '',
            'autoptimize_js_include_inline'  => 'on',
            'autoptimize_js_justhead'  => '',
            'autoptimize_js_trycatch'  => '',
            'autoptimize_minify_excluded'  => 'on',
            'autoptimize_optimize_checkout'  => '',
            'autoptimize_optimize_logged'  => 'on',
            'autoptimize_service_availablity'  => 'a:3:{s:12:"extra_imgopt";a:3:{s:6:"status";s:2:"up";s:5:"hosts";a:1:{i:1;s:28:"https://sp-ao.shortpixel.ai/";}s:16:"launch-threshold";s:4:"4096";}s:7:"critcss";a:2:{s:6:"status";s:2:"up";s:5:"hosts";a:1:{i:1;s:24:"https://criticalcss.com/";}}s:9:"rapidload";a:1:{s:6:"status";s:4:"down";}}',
        ];

        if (class_exists('autoptimizeCache')) {
            autoptimizeCache::clearall();
        }

        return [
            'status'  => true,
            'message' => "- Autoptimize plugin is installed and active and removed cache",
        ];
    }

    function check_speed()
    {
        return [
            'status'  => false,
            'message' => "- Please test speed manually using tools like Google PageSpeed Insights or GTmetrix.",
        ];
    }

    function check_broken_links()
    {

        $args = [
            'post_type'      => 'any',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
        ];

        $posts = get_posts($args);

        $broken_links = [];
        $result = [];
        $seen = [];
        $message = '';

        foreach ($posts as $post) {
            $content = get_post_field('post_content', $post->ID);

            if (preg_match_all('/<a[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/i', $content, $matches)) {
                foreach ($matches[1] as $link) {

                    if (!empty($link)) {
                        if ($link === '#' || strpos($link, '#') !== false) {
                            continue;
                        }

                        // Check if the link is broken (this is a simplified check)
                        if (strpos($link, home_url()) === 0) {
                            $broken_links[] = [
                                'url' => $link,
                                'post_id' => $post->ID,
                                'type' => 'internal',
                                'permalink' => get_permalink($post->ID),
                            ];
                        } else {

                            $url = $this->is_valid_url($link);
                            $broken_links[] = [
                                'url' => $link,
                                'type' => 'external',
                                'status' => $url ? 'valid' : 'invalid',
                                'permalink' => get_permalink($post->ID),
                            ];
                        }
                    }
                }
            }
        }

        foreach ($broken_links as $item) {
            $url = $item['url'];
            if (!isset($seen[$url])) {
                $a = [];

                if ($item['type'] === 'external' && $item['status'] === 'valid') {
                    $response = wp_remote_head($url);

                    if (is_wp_error($response)) {
                        $a =  [
                            'status' => 'error',
                            'code'   => 0,
                            'message' => $response->get_error_message(),
                        ];
                    }

                    $code = wp_remote_retrieve_response_code($response);

                    if (in_array($code, [401, 403])) {
                        $message .= "- (HTTP $code) $url found on page {$item['permalink']} \n";
                    } else if ($code == 404) {
                        $message .= "- (HTTP $code) $url found on page {$item['permalink']} (HTTP $code)\n";
                    } else if ($code >= 500) {
                        $message .= "- (HTTP $code) $url found on page {$item['permalink']} (HTTP $code)\n";
                    }
                } else if ($item['type'] === 'external' && $item['status'] === 'invalid') {
                    $message .= "- (HTTP) $url found on page {$item['permalink']}\n";
                }

                $seen[$url] = true;
                $result[] = $a;
            }
        }

        if (empty($message)) {
            return [
                'status'  => true,
                'message' => "- No broken links found.",
            ];
        }

        return [
            'status'  => false,
            'message' => $message,
        ];
    }

    public function is_valid_url($url)
    {
        if (empty($url)) {
            return false;
        }

        $url = trim($url);

        // loại link rác
        if (
            $url === '#' ||
            str_starts_with($url, 'javascript:') ||
            str_starts_with($url, 'mailto:') ||
            str_starts_with($url, 'tel:')
        ) {
            return false;
        }

        // URL nội bộ dạng /page/2
        if (str_starts_with($url, '/')) {
            return true;
        }

        // Chỉ cho phép http / https
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }

        // Check cú pháp URL cơ bản
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Parse host
        $host = parse_url($url, PHP_URL_HOST);

        if (!$host) {
            return false;
        }

        //  host không có dấu chấm → loại (page, localhost, abc)
        if (strpos($host, '.') === false) {
            return false;
        }

        if (!preg_match('/\.[a-z]{2,}$/i', $host)) {
            return false;
        }

        return true;
    }

    function check_seo_analytics()
    {
        include_once ABSPATH . 'wp-admin/includes/plugin.php';
        $result = [];
        $message = '';
        $active_seo = null;
        $errs = [];
        $errs_seo = [];

        $seo_plugins = [
            'yoast' => [
                'name' => 'Yoast SEO',
                'file' => 'wordpress-seo/wp-seo.php',
            ],
            'rankmath' => [
                'name' => 'Rank Math',
                'file' => 'seo-by-rank-math/rank-math.php',
            ],
        ];

        foreach ($seo_plugins as $key => $plugin) {
            if (is_plugin_active($plugin['file'])) {
                $active_seo = $key;
                break;
            }
        }

        $message .= "SEO Plugins Status:\n";

        if (!$active_seo) {
            $message .= "- No SEO plugin detected (Yoast / Rank Math)\n";
            $errs[] = 'no_seo_plugin';
        } else {
            $message .= $seo_plugins[$active_seo]['name'] . " is active\n";
        }

        // 2. CHECK SITEMAP URL
        $sitemaps = [
            'yoast'    => home_url('/sitemap_index.xml'),
            'rankmath' => home_url('/sitemap_index.xml'),
        ];

        $sitemap_url = $sitemaps[$active_seo] ?? null;
        $check_url  = 'http://maintenance.dev.enosta.com/handle.php?content=head&action=' . urlencode($sitemap_url);

        // Check sitemap URL
        $response = wp_remote_get($check_url, [
            'timeout' => 200,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            $message .= " Failed to fetch sitemap: " . $response->get_error_message() . "\n";
            $errs[] = 'sitemap_fetch_error';
        } else {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (isset($data['status']) && $data['status'] == 200) {
                $message .= "- Sitemap is accessible: " . $sitemap_url . "\n";
            } else {
                $message .= "- Sitemap is not accessible: " . $sitemap_url . "\n";
                $errs[] = 'sitemap_not_accessible';
            }
        }

        $site_url = home_url('/');
        $check_url  = 'http://maintenance.dev.enosta.com/handle.php?action=' . urlencode($site_url);

        $response = wp_remote_get($check_url, [
            'timeout' => 200,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            ],
            'sslverify' => false,
        ]);


        $message .= "SEO & Analytics Check Results:\n";

        if (is_wp_error($response)) {
            $message .= "- Failed to fetch site URL: " . $response->get_error_message() . "\n";
            $errs[] = 'site_fetch_error';
        } else {
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            // check GA code, Search Console verification
            if (isset($data['status']) && $data['status'] == 200) {
                // Further checks can be implemented here
                $html = $data['data'];

                $checks = [
                    'ga4' => [
                        'label'   => 'Google Analytics 4',
                        'pattern' => '/googletagmanager\.com\/gtag\/js/i',
                    ],
                    'gtm' => [
                        'label'   => 'Google Tag Manager',
                        'pattern' => '/googletagmanager\.com\/gtm\.js/i',
                    ],
                    'gsc' => [
                        'label'   => 'Google Search Console',
                        'pattern' => '/<meta\s+name=["\']google-site-verification["\']/i',
                    ],
                ];

                $tracking_results = [];

                foreach ($checks as $key => $check) {
                    if (preg_match($check['pattern'], $html) != 1) continue;
                    $tracking_results[$key] = [
                        'label'  => $check['label'],
                        'exists' => preg_match($check['pattern'], $html) === 1,
                    ];
                }


                /**
                 * Check results
                 * - If none found → warning
                 * - If some found → list results
                 */
                if (empty($tracking_results)) {
                    $message .= "- No tracking codes found on the site.\n";
                } else {
                    foreach ($tracking_results as $r) {
                        if ($r['exists']) {
                            $message .= "    {$r['label']} found\n";
                        } else {
                            // $message .= "    {$r['label']} NOT found\n";
                            // $errs_seo[] = 'missing_' . strtolower(str_replace(' ', '_', $r['label']));
                        }
                    }
                }
            } else {
                $message .= "- Site URL is not accessible: " . $site_url;
                $errs[] = 'site_not_accessible';
            }
        }

        return [
            'status'  => empty($errs) ? true : false,
            'message' => $message,
        ];
    }

    private function maintenance()
    {

        $permission = $this->check_permission_files();
        est_add_maintenance_step(
            'permission',
            $permission['status'],
            $permission['message'],
            'Check File Permissions'
        );

        $clean_database = $this->clean_database();
        est_add_maintenance_step(
            'clean_database',
            $clean_database['status'],
            $clean_database['message'],
            'Clean Database'
        );

        $cache = $this->optimize_performance();
        est_add_maintenance_step(
            'cache',
            $cache['status'],
            $cache['message'],
            'Cache & Performance Optimization'
        );

        $speed = $this->check_speed();
        est_add_maintenance_step(
            'speed',
            $speed['status'],
            $speed['message'],
            'Test Speed'
        );

        $broken_links = $this->check_broken_links();
        est_add_maintenance_step(
            'broken_links',
            $broken_links['status'],
            $broken_links['message'],
            'Check broken links'
        );

        $seo_analytics = $this->check_seo_analytics();
        est_add_maintenance_step(
            'seo_analytics',
            $seo_analytics['status'],
            $seo_analytics['message'],
            'Check SEO & Analytics'
        );

        est_report();
    }

    function run()
    {
        $this->maintenance();
    }
}
