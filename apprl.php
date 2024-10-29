<?php
/**
 * @package APPRL
 */
/*
Plugin Name: APPRL for WordPress
Plugin URI: http://apprl.com/
Description: This plugin automatically creates APPRL-links from the original retailer links that you enter in your post. Remember to mark your posts as commercial content with "adlinks", "sponsored" or the equivalent standard in your country.
Version: 1.0.3
Author: apprl
License: GPLv2 or later
Text Domain: apprl
*/

// Make sure we don't expose any info if called directly
if ( !function_exists( 'add_action' ) ) { exit; }

define( 'APPRL__PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'APPRL__SETTINGS_PAGE', home_url('wp-admin/options-general.php?page=apprl-config') );
define( 'APPRL__API_KEY', 'SXGbmK8UcuJI9PgRUUqsNxTmyATECdFmTyEgfWR5');


function apprl_activate() {
    // GOTCHA: will this always be set to the latest version of the plugin?
	add_option( 'apprl_version', '1.0.3');
	add_option( 'apprl_activation_date', time());
	add_option( 'apprl_cron_running', false);

	if ( ! wp_next_scheduled( 'apprl_reprocess_auto_hook' ) ) {
		wp_schedule_event( time(), 'hourly', 'apprl_reprocess_auto_hook' );
	}
}

function apprl_deactivate() {
	delete_option( 'apprl_version' );
	delete_option( 'apprl_token' );
	delete_option( 'apprl_error' );
	delete_option( 'apprl__auto_transform' );

	wp_clear_scheduled_hook('apprl_reprocess_auto_hook');
}

register_activation_hook( __FILE__, 'apprl_activate' );
register_deactivation_hook( __FILE__, 'apprl_deactivate' );

add_action('apprl_reprocess_auto_hook', 'apprl_cron');



if ( is_admin() ) {

	require_once( APPRL__PLUGIN_DIR . 'class.apprl-admin.php' );
	require_once( APPRL__PLUGIN_DIR . 'class.apprl-api.php' );

	add_action( 'init', array( 'Apprl_Admin', 'init' ) );
	add_action( 'admin_init', array( 'Apprl_Admin', 'settings') );

} else {
	add_filter( 'the_content', 'apprl_filter_content' );
}

function apprl_cron() {
	if( defined( 'DOING_CRON' ) ) {
		if( !get_option( 'apprl_cron_running' ) ) {
			update_option( 'apprl_cron_running', true );
		}

		require_once( APPRL__PLUGIN_DIR . 'class.apprl-admin.php' );
		require_once( APPRL__PLUGIN_DIR . 'class.apprl-api.php' );

		Apprl_Admin::init();

		update_option( 'apprl_cron_running', false );
	}
}


function apprl_filter_content( $content ) {
	$auto = get_option( 'apprl__auto_transform' );
	$showAdLinkSpan = get_option( 'apprl__auto_span' );
	if( $auto == 'Yes' ) {
		$postId = $GLOBALS['post']->ID;
		$optout = get_post_meta( $postId, 'apprl__auto_transform_optout', true );

		if( empty($optout) ) {
			$linksJson = get_post_meta( $postId, 'apprl__links', true );

			// Replace old links with new links
			if( !empty($linksJson) ) {
				$content = apprl_do_auto_replace( $content, (array)json_decode( $linksJson ), $showAdLinkSpan );
			}
		}
	}

	return $content;
}

function apprl_do_auto_replace( $content = '', $links = array(), $showAdLinkSpan) {
	if( is_array( $links ) && !empty( $links ) ) {
		$dom = new DOMDocument('1.0', 'UTF-8');
		$dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'));
		$anchors = $dom->getElementsByTagName('a');
		foreach( $links as $oldLink => $newLink ) {
			if( !empty( $newLink ) ) {
				foreach ($anchors as $anchor) {
					if($anchor->getAttribute('href') == $oldLink) {
						$anchor->setAttribute('href', $newLink);
						if ($showAdLinkSpan == 'Yes') {
							$span = $dom->createElement('span', '(adlink)');
							$span->setAttribute('class', 'apprl-link');
							$span->setAttribute('style', 'margin-left:0.5ex');
							$anchor->parentNode->insertBefore($span, $anchor->nextSibling);
						}
					}
				}
			}
		}
		$html = '';
		$nodes = $dom->documentElement->firstChild->childNodes;
		if (is_array($nodes) || is_object($nodes)) {
			foreach ($nodes as $node) {
				$html .= $dom->saveHTML($node);
			}
		}
		return $html;
	} else {
		// if no links return original content
		return $content;
	}
}
