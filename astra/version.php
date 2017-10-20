<?php

/**
 * Defines the version and other meta-info about the plugin
 *
 * Setting the $plugin->version to 0 prevents the plugin from being installed.
 * See https://docs.moodle.org/dev/version.php for more info.
 *
 * @package    mod_astra
 * @copyright  2017 Aalto SCI CS dept.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_astra';
$plugin->version = 2017102000;
$plugin->release = 'v1.3';
$plugin->requires =  2016120500; // Moodle 3.2
$plugin->maturity = MATURITY_STABLE;
$plugin->dependencies = array(
        'theme_boost' => 2016120500, // so that the Bootstrap 4 framework is available
);
