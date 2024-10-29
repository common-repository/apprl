<?php
use OAuth\OAuth2\Service\Apprl;
use OAuth\Common\Storage\Session;
use OAuth\Common\Consumer\Credentials;

class Apprl_Api {
	public $service, $storage, $credentials = null;
	public $connected = false;
	private $token, $parentName, $error = null;

	public function __construct( $parent = '' ) {
		require_once( APPRL__PLUGIN_DIR . 'lib/PHPoAuthLib/vendor/autoload.php' );
		$this->storage = new Session();
		$this->parentName = $parent;
	}

	public function init() {

		// Prepare some basics for the oAuth lib
		$uriFactory = new \OAuth\Common \Http\Uri\UriFactory();
		$currentUri = $uriFactory->createFromSuperGlobalArray($_SERVER);
		$currentUri->setQuery('');

		// Setup the credentials for the requests
		$this->credentials = new Credentials( APPRL__API_KEY, null, APPRL__SETTINGS_PAGE );		

		// If saved token avilable, use it 
		$token = $this->tokenRetrieve();
		if( is_object( $token ) ) $this->storage->storeAccessToken( 'Apprl', $token );

		// Instantiate the service using the credentials, http client and storage mechanism for the token
		$serviceFactory = new \OAuth\ServiceFactory();
		$this->service = $serviceFactory->createService( 'Apprl', $this->credentials, $this->storage, array( 'read' ) );

		// Check if token still is valid, refresh if not
		if( is_object( $this->token ) ) {
			if( !$this->token->isExpired() ) {
				$this->connected = true;
			} else {
				$this->tokenRefresh( $this->token );
			}
		}

		/*$error = get_option( 'apprl_error' );
		if( !empty( $error ) ) {
			$this->error = $error;
			$this->connected = false;
			if( $error == 'forbidden' ) {
				add_action( 'admin_notices', array($this->parentName, 'error_notice_'.$error) );
			}
		}*/
	}


	public function requestLink( $data, $redo = false) {
		// Request links
		try {
            // GOTCHA: will get_option('apprl_version') always be set to the latest version of the plugin? See apprl_activate()
			$response = $this->service->request( '/links/',
			                                    'POST',
			                                    $data,
			                                    array('X-Extension-Type' => 'wp', 'X-Extension-Version' => get_option('apprl_version'))
			                                    );
		} catch( Exception $e ) {
			if( $error = $this->checkError($e->getMessage()) ) {
				if( $error == 'expire' ) {
					if( $this->tokenRefresh( $this->token ) ) {
						// Try again if we successfully refreshed token
						if( !$redo ) {
							$response = $this->requestLink( $data, true );
						}
					}
				} else if( $error == 'forbidden' ) {
					update_option( 'apprl_error', 'forbidden' );
					$this->error = 'forbidden';
				}
			}

		}

		if( !is_null( $this->error ) ) {
			$response = '{"links": [null], "status": "'.$this->error.'"}';
		} else {
			$this->error = null;
			delete_option( 'apprl_error' );
		}

		return $response;
	} 

	private function checkError($msg = null) {
		if( strpos( $msg, 'Token expired' ) !== false ) {
			return 'expire';
		} else if( strpos( $msg, 'HTTP/1.0 403 Forbidden' ) !== false ) {
			return 'forbidden';
		}

		return 'unknown';
	}

	public function authorize( $do ) {
		if( $do == 'connect' ) {
			$url = $this->service->getAuthorizationUri();
			header( 'Location: ' . $url );
			exit;
		} else if( $do == 'disconnect' ) {
			$this->tokenRemove();
		}
	}

	public function getAccessToken() {
		if ( !empty( $_GET['code'] ) && !$this->connected ) {
			// Retrieve the CSRF state parameter
			$state = isset( $_GET['state'] ) ? $_GET['state'] : null;

			// This was a callback request, get the token
			try {
				$token = $this->service->requestAccessToken( $_GET['code'], $state );
			} catch(Exception $e) {
				if(strpos($e->getMessage(), 'HTTP/1.0 401 Unauthorized')) {
					$this->error = 'forbidden';
					$this->connected = false;
				}
			}

			if( $token ) {
				$this->tokenStore( $token );
				$this->connected = true;
				delete_option( 'apprl_error' );
			}
		}
	}


	public function tokenRefresh( $token = null ) {
		$token = (is_null($token)) ? $this->token : $token;

		try {
			$new_token = $this->service->refreshAccessToken( $token );
		} catch( Exception $e ) {
			if( strpos( $e->getMessage(), 'HTTP/1.0 401 Unauthorized' ) ) {
				// Do something?
				add_action( 'admin_notices', array($this->parentName, 'error_notice_unauthorized') );
				$this->error = 'denied_refresh';
				$this->connected = false;
			}
		}

		if(isset( $new_token ) && is_object( $new_token )) {
			$this->tokenStore( $new_token );
			$this->token = $new_token;
			$this->connected = true;
			$this->error = null;
			delete_option( 'apprl_error' );
			return true;
		}

		return false;
	}
	public function tokenStore( $token ) {
		update_option( 'apprl_token', $token );
	}
	public function tokenRetrieve() {
		$token = get_option( 'apprl_token' );
		$this->token = ( $token ) ? $token : null;
		return $this->token;
	}
	public function tokenRemove() {
		$this->token = ( delete_option( 'apprl_token' ) ) ? null : $this->token;
		$this->connected = false;
		delete_option( 'apprl_error' );
	}

}
