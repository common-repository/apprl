<?php
use OAuth\OAuth2\Service\Apprl;
use OAuth\Common\Storage\Session;
use OAuth\Common\Consumer\Credentials;
define('EXT_LINK_TYPE', 'Ext-Link-WP');

class Apprl_Admin {

	private static $initiated = false;

	private static $api = null;

	private static $phpself = '';
	private static $currentPage = '';
	private static $requestUri = '';
	private static $ajaxUri = '';
	private static $isEditorPage = false;
	private static $isPluginsPage = false;

	public static function init() {
		if ( ! self::$initiated ) {
			self::$phpself = basename($_SERVER['PHP_SELF']);

			// Set up som basic vars
			self::$currentPage 		= ( isset( $_GET['page'] ) ) ? $_GET['page'] : '';
			self::$requestUri 		= ( isset( $_SERVER['REQUEST_URI'] ) ) ? $_SERVER['REQUEST_URI'] : '';
			self::$isEditorPage 	= in_array( self::$phpself,  array('post-new.php', 'page-new.php', 'post.php', 'page.php') );
			self::$isPluginsPage 	= ( self::$phpself == 'plugins.php' ) ? true : false;
			self::$ajaxUri 			= '/wp-admin/admin-ajax.php';

			self::init_oauth();
			self::init_hooks();
		}
	}

	/**
	 * Prepare oauth usage
	 */
	public static function init_oauth() {
		$settingsPage = self::get_page_name();
		if( self::$isEditorPage || self::$currentPage == $settingsPage || self::$isPluginsPage || self::$requestUri == self::$ajaxUri || defined( 'DOING_CRON' ) ) {
			self::$api = new Apprl_Api('Apprl_Admin');
			self::$api->init();
		}

		if( self::$currentPage == $settingsPage && isset($_GET['do']) ) {
			self::$api->authorize( $_GET['do'] );
		}

		if(self::$currentPage == $settingsPage) {
			$error = get_option( 'apprl_error' );
			if( !empty( $error ) ) {
				if( $error == 'forbidden' ) {
					add_action( 'admin_notices', array( 'Apprl_Admin', 'error_notice_'.$error ) );
				}
			}
		}

		// Show notice on plugins page
		if(self::$phpself == 'plugins.php' && !self::$api->connected) {
			add_action( 'admin_notices', array( 'Apprl_Admin', 'notice_authenticate' ) );
		}
	}

	/**
	 * Add all misc hooks
	 */
	public static function init_hooks() {

		// Add settings menu
		add_action( 'admin_menu', array( 'Apprl_Admin', 'admin_menu' ) );
		add_filter( 'plugin_action_links_'.plugin_basename( APPRL__PLUGIN_DIR . 'apprl.php'), array( 'Apprl_Admin', 'admin_plugin_settings_link' ) );

		// Auto link hook
		add_filter( 'wp_insert_post_data', array( 'Apprl_Admin', 'modify_links' ), '99', 2 );

		// Auto link opt-out hooks
		add_action( 'post_submitbox_misc_actions', array( 'Apprl_Admin', 'auto_optout' ) );
		add_action( 'save_post', array( 'Apprl_Admin', 'save_postdata' ) );

		wp_enqueue_style( 'apprl', plugins_url('apprl') . '/css/apprl.css' );

    	self::$initiated = true;
	}

	public static function modify_links($data, $postarr) {
		$notIn = array( 'auto-draft', 'draft' );

		if( !in_array( $data['post_status'], $notIn ) ) {
			$postId = $postarr['ID'];
			$auto = get_option( 'apprl__auto_transform' );
			$autoOptOut = get_option( 'apprl__auto_transform_optout' );

			if( $autoOptOut !== true && $auto == 'Yes' && self::$api->connected ) {

				// Set type to enable separating links created with this plugin from others
				$links 	= array( 'links' => array(), 'links_type' => EXT_LINK_TYPE, );
				$save 	= array();

				// Get all href in text
				$dom = new DOMDocument;
				$dom->loadHTML( $data['post_content'] );
				$xpath = new DOMXPath( $dom );
				$nodes = $xpath->query( '//a' );
				
				foreach( $nodes as $a ) {
					$href = $a->getAttribute( 'href' );

					$links['links'][] = str_replace( '\"', '', $href );
				}

				// Request the links
				if( !empty( $links['links'] ) && $response = self::$api->requestLink( json_encode( $links ) ) ) {
					$response = json_decode( $response );

					// Create an array for saving
					for( $i = 0; $i < sizeof( $links['links'] ); $i++ ) {
						if( isset( $links['links'][$i], $response->links[$i] ) && $response->links[$i] !== NULL ) {
							$save[$links['links'][$i]] = $response->links[$i];
						}
					}
				}

				// If we have anything to save, do it. Otherwise remove the post_meta
				if( !empty( $save ) ) {
					if ( ! add_post_meta( $postId, 'apprl__links', json_encode( $save ), true ) ) { 
						update_post_meta( $postId, 'apprl__links', json_encode( $save ) );
					}
				} else {
					delete_post_meta( $postId, 'apprl__links' );
				}
			}
		}

		return $data;
	}

	/**
     * Actions on 'admin_menu'
     */
	public static function admin_menu() {
		// Add the options page
		$hook = add_options_page(
			__( 'APPRL', 'apprl' ), 
			__( 'APPRL', 'apprl' ), 
			'manage_options', 
			'apprl-config', 
			array( 'Apprl_Admin', 'options_page' ) 
		);
	}

	/**
     * Add settings link
     */
	public static function admin_plugin_settings_link( $links ) { 
  		$settings_link = '<a href="'.esc_url( self::get_page_url() ).'">'.__( 'Settings', 'apprl' ).'</a>';
  		array_unshift( $links, $settings_link );
  		return $links; 
	}

	public static function options_page() {
		self::$api = new Apprl_Api();
		self::$api->init();
		self::$api->getAccessToken();

		include APPRL__PLUGIN_DIR . '/views/options.inc.php';
	}
	public static function get_page_name( $page = 'config' ) {
		return 'apprl-'.$page;
	}
	public static function get_page_url( $page = 'config' ) {
		$args = array( 'page' => self::get_page_name( $page ) );
		$url = add_query_arg( $args, admin_url( 'options-general.php' ) );
		return $url;
	}



	/* * 
	 * Notices
	 */

	public static function notice_authenticate() {
		?>
		<div class="notice updated apprl-notice">
			<button name="apprl_connect" id="apprl_connect" class="button button-primary" onclick="window.location.href='<?php echo APPRL__SETTINGS_PAGE; ?>&do=connect'"><?php echo __( 'Connect', 'apprl' ); ?></button>
				<span><?php echo __( 'Connect to your APPRL-account to finish installation', 'apprl' ); ?></span>
		</div>
		<?php
	}



	/* * 
	 * Error notices
	 */

	public static function error_notice_unauthorized() {
		?>
		<div class="error notice">
			<p><?php echo __( 'Failed to refresh token', 'apprl' ); ?></p>
		</div>
		<?php
	}

	public static function error_notice_forbidden() {
		?>
		<div class="error notice">
			<p><?php echo __( 'Failed to perform request to APPRL. Your authentication may have expired. Try connecting again.', 'apprl' ); ?></p>
		</div>
		<?php
	}



	/**
	 * Opt-out form
	 */

	public static function auto_optout( $post ) {
		$auto = get_option( 'apprl__auto_transform' );
		if( self::$api->connected && $auto == 'Yes' ) {
			$value = get_post_meta( $post->ID, 'apprl__auto_transform_optout', true );
			require( APPRL__PLUGIN_DIR . 'views/optout.options.inc.php' );
		}
	}

	public static function save_postdata( $postid ) {
		$name = 'apprl__auto_transform_optout';

		if ( (defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE) || empty( $postid ) ) return false;

		// If box is checked, delete the opt-out meta
		if( isset( $_POST[$name] ) || empty( $_POST ) ) {
			delete_post_meta( $postid, $name );
		} else{
			add_post_meta( $postid, $name, 1, true );
		}
	}



	/**
	 * Settings form
	 */

	public static function settings() {
		// Add the section
		add_settings_section(
			'apprl_setting_section',
			'',
			array( 'Apprl_Admin', 'setting_section_callback_function' ),
			'apprl_settings'
		);

		// Add the field
		add_settings_field(
			'apprl__auto_transform',
			'Enable APPRL auto-linking on your posts?',
			array( 'Apprl_Admin', 'setting_callback_function' ),
			'apprl_settings',
			'apprl_setting_section'
		);

		// Add the field
		add_settings_field(
			'apprl__auto_span',
			'Show (adlink) after auto-links?',
			array( 'Apprl_Admin', 'setting_callback_spans' ),
			'apprl_settings',
			'apprl_setting_section'
		);
		// Register setting so that $_POST handling is done for us
		register_setting( 'apprl_settings', 'apprl__auto_span' );
		// Register setting so that $_POST handling is done for us
		register_setting( 'apprl_settings', 'apprl__auto_transform' );
	}

	public static function setting_section_callback_function() {}

	public static function setting_callback_function() {
		$name = 'apprl__auto_transform';
		$value = get_option( $name );

		if( empty( $value ) ) {
			$value = 'No';
		}
		require( APPRL__PLUGIN_DIR . 'views/field.options.inc.php' );
	}

	public static function setting_callback_spans() {
		$name = 'apprl__auto_span';
		$value = get_option( $name );

		if( empty( $value ) ) {
			$value = 'No';
		}
		require( APPRL__PLUGIN_DIR . 'views/field.options.inc.php' );
	}

}
