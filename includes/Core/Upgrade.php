<?php

namespace FBCallNow\Core;

/**
 * One-time upgrades of saved settings, gated on a stored database version
 *
 * @package FBCallNow\Core
 */
class Upgrade {

    /**
     * Option holding the last upgrade that ran
     */
    const OPTION = 'fbcn_db_version';

    /**
     * Current database version
     */
    const DB_VERSION = 1;

    /**
     * Run any upgrades this site has not had yet
     */
    public static function maybe_upgrade() {
        $from = (int) get_option(self::OPTION, 0);
        if ($from >= self::DB_VERSION) {
            return;
        }

        if ($from < 1) {
            self::upgrade_wrap_end_of_day();
        }

        update_option(self::OPTION, self::DB_VERSION);
    }

    /**
     * CR385: before CR278 the End Time dropdown stopped at 23:00, and "Wrap to Next Day" with
     * end 23:00 kept the button up through 23:59. That special case is gone and the end time
     * is exclusive, so carry those saved windows over to 24:00 (end of day). Windows without
     * wrap already ended at 23:00 and are left alone.
     */
    private static function upgrade_wrap_end_of_day() {
        $pro_settings = get_option('fbcn_pro_settings');
        if (!is_array($pro_settings)) {
            return;
        }

        if (!empty($pro_settings['wrap_to_next_day']) && ($pro_settings['end_time'] ?? '') === '23:00') {
            $pro_settings['end_time'] = '24:00';
            update_option('fbcn_pro_settings', $pro_settings);
            Logger::info('Upgrade: wrapped window end time 23:00 moved to 24:00 (end of day)');
        }
    }
}
