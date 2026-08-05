<?php
/*
Plugin Name: Bootstrap Fallback Text Domain
Domain Path: /languages
*/

$GLOBALS['wp_bootstrap_plugin_observations']['fallback'] = array(
	'get_file_data'   => function_exists( 'get_file_data' ),
	'get_plugin_data' => function_exists( 'get_plugin_data' ),
);
