<?php
defined('WP_UNINSTALL_PLUGIN') || exit;
// Keep derivatives usable by cached pages. Removal is an explicit Media Library action.
delete_option('qif_job');
wp_unschedule_hook('qif_convert');
