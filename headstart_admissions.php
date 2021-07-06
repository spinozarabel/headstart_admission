<?php
/**
*Plugin Name: Head Start Admissions
*Plugin URI:
*Description: Head Start Admissions Plugin - Takes care of Registration, forms, SriToni account, Cashfree Account and payment using Woocommerce REST API
*Version: 2021070500
*Author: Madhu Avasarala
*Author URI: http://sritoni.org
*Text Domain: headstart_admissions
*Domain Path:
*/
// no direct access allowed
defined( 'ABSPATH' ) or die( 'No direct access allowed' );

// require_once(__DIR__."/MoodleRest.php");   				           // Moodle REST API driver for PHP
// file containing class for settings submenu and page
require_once(__DIR__."/class_headstart_admission_settings.php");

// this is tile containing the class definition for virtual accounts e-commerce
require_once(__DIR__."/class_headstart_admission.php");

// require_once(__DIR__."/cfAutoCollect.inc.php");         // contains cashfree api class

if ( is_admin() )
{
  // This is to be done only once!!!!
  $admission_settings = new class_headstart_admission_settings();
}

// wait for all plugins to be loaded before initializing the new VABACS gateway
add_action('plugins_loaded', 'init_headstart_admission');

public function init_headstart_admission()
{
  // instantiate the class for head start admission
  $admission       = new class_headstart_admission();
}
