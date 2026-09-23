<?php

class Weekly
{
    private function maintenance()
    {
        $options = get_option('maintenance_options', []);
        $mail = esc_attr($options['mail_test'] ?? 'webdevdevelopment@enosta.com');

        // STEP 1: BACKUP WEBSITE
        $backup_website = est_backup_site();
        est_add_maintenance_step(
            'backup_website',
            $backup_website['status'],
            $backup_website['message'],
            'Backup website'
        );

        // STEP 2: UPDATE WORDPRESS CORE
        $update_wordpress = est_update_wordpress_core();
        est_add_maintenance_step(
            'update_wordpress',
            $update_wordpress['status'],
            $update_wordpress['message'],
            'Update Core'
        );

        // STEP 3: UPDATE PLUGIN
        $update_plugin = est_update_plugins_from_snapshot();
        est_add_maintenance_step(
            'update_plugin',
            $update_plugin['status'],
            $update_plugin['message'],
            'Update plugins'
        );

        // STEP 4: SEND MAIL
        $update_plugin = est_send_test_mail(
            $mail,
            'Website Maintenance Report',
            'Test mail.'
        );
        est_add_maintenance_step(
            'send_mail',
            $update_plugin['status'],
            $update_plugin['message'],
            'Test email'
        );

        // STEP 5: CHECK SECURITY (placeholder)
        (new EST_SecurityEngine())->scan();
    }

    function run()
    {
        $this->maintenance();
    }
}
