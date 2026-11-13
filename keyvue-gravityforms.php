<?php
/**
 * Plugin Name: KeyVue voor Gravity Forms
 * Description: Verbind Gravity Forms met KeyVue, met veldkoppeling, wachtrij en verzendstatus.
 * Version: 2.0.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: Websitebeheer
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) { exit; }
require_once __DIR__ . '/includes/class-keyvue-gf.php';
register_activation_hook(__FILE__, array('KeyVue_GF', 'install'));
register_deactivation_hook(__FILE__, array('KeyVue_GF', 'deactivate'));
KeyVue_GF::boot();
