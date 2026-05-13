<?php

/*
Plugin Name:  Elisa Desk for Gravity Forms
Plugin URI:   https://github.com/generoi/gravityforms-elisadesk
Description:  Gravity Forms feed addon that posts entries to Elisa Desk's generic form API. Supports field mapping, multiple feeds per form, conditional logic, and file uploads (multipart).
Version:      1.0.0
Author:       Genero
Author URI:   https://genero.fi/
License:      MIT License
Text Domain:  gravityforms-elisadesk
*/

use Genero\ElisaDesk\Plugin;

defined('ABSPATH') || exit;

if (file_exists($composer = __DIR__.'/vendor/autoload.php')) {
    require_once $composer;
}

Plugin::getInstance(__FILE__);
