<?php
/**
 * Simple Post Notes uninstall: removes the plugin settings.
 * Notes (the _spnote post meta) are kept.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('spnotes_settings');
