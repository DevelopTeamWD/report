<?php

if (!defined('ABSPATH')) {
    return;
}

class EST_SecurityEngine
{

    public function __construct() {}
    public function scan()
    {
        wfConfig::set('wfKillRequested', 1);
        wfUtils::clearScanLock();

        try {
            wfScanEngine::startScan();

            error_log('Start wfScanEngine::startScan');

            // Create start time if not exists
            $start_time = get_option('maintenance_time_start');

            if ($start_time === false) {
                $start_time = current_time('mysql');
                add_option('maintenance_time_start', $start_time, '', false);
            } else {
                // Update lại start time nếu đã tồn tại
                $start_time = current_time('mysql');
                update_option('maintenance_time_start', $start_time, false);
            }

            // END TIME = start + 30 minutes
            $end_time = date(
                'Y-m-d H:i:s',
                strtotime($start_time . ' +6 minutes')
            );

            if (get_option('maintenance_time_end') === false) {
                add_option('maintenance_time_end', $end_time, '', false);
            } else {
                update_option('maintenance_time_end', $end_time, false);
            }

            // Trigger to complated
            wp_schedule_single_event(
                time(),
                'est_scan_monitor'
            );

            $wfScan = new wfScanEngine();
            $wfScan->go();
        } catch (wfScanEngineTestCallbackFailedException $e) {
            wfConfig::set('lastScanCompleted', $e->getMessage());
            wfConfig::set('lastScanFailureType', wfIssues::SCAN_FAILED_CALLBACK_TEST_FAILED);
            wfUtils::clearScanLock();
        } catch (Exception $e) {
            if ($e->getCode() != wfScanEngine::SCAN_MANUALLY_KILLED) {
                wfConfig::set('lastScanCompleted', $e->getMessage());
                wfConfig::set('lastScanFailureType', wfIssues::SCAN_FAILED_GENERAL);
            }
        }
    }
}
