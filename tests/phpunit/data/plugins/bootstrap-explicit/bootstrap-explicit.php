<?php
/*
Plugin Name: Bootstrap Explicit Text Domain
Text Domain: bootstrap-explicit-domain
Domain Path: /translations
*/

$GLOBALS['wp_bootstrap_plugin_observations']['explicit'] = array(
	'get_file_data'   => function_exists( 'get_file_data' ),
	'get_plugin_data' => function_exists( 'get_plugin_data' ),
);
