<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Wprus_Api_Abstract {
	const TOKEN_EXPIRY_BUFFER = MINUTE_IN_SECONDS / 2;

	protected static $encryption_settings;
	protected static $ip_whitelist;
	protected static $settings_class;
	protected static $can_request_token = true;

	protected $settings;
	protected $method;
	protected $endpoint;
	protected $data;
	protected $async_user_id;
	protected $doing_remote_action = false;
	protected $remote_async_actions;
	protected $role_handler;

	public function __construct( $endpoint, $settings, $init_hooks = false ) {
		$this->endpoint             = $endpoint;
		$this->settings             = $settings;
		$this->doing_remote_action  = self::is_doing_remote_action();
		$this->remote_async_actions = array();

		$this->init();
		$this->init_data();

		if ( $init_hooks ) {

			if ( $this->doing_remote_action ) {
				add_action( 'init', array( $this, 'init_remote_hooks_authorization' ), -10, 0 );
				add_action( 'init', array( $this, 'init_remote_hooks' ), -10, 0 );
			} else {
				add_action( 'init', array( $this, 'init_local_hooks' ), 0, 0 );
			}

			if ( $this->has_remote_async_actions() ) {
				add_action( 'init', array( $this, 'init_async_hooks' ), 0, 0 );
			}

			add_action( 'wp_ajax_wprus_' . $endpoint . '_notify_ping_remote', array( $this, 'notify_ping_remote' ), 10, 0 );

			add_filter( 'wprus_wp_endpoints', array( $this, 'add_action_endpoints' ), 10, 1 );
		}
	}

	/*******************************************************************
	 * Public methods
	 *******************************************************************/

	public static function is_doing_remote_action() {
		return strpos( $_SERVER['REQUEST_URI'], '/wprus/' ) !== false;
	}

	public function encrypt_data( $data ) {
		$bundle = Wprus_Crypto::encrypt(
			wp_json_encode( $data ),
			self::$encryption_settings['aes_key'],
			self::$encryption_settings['hmac_key']
		);

		return $bundle;
	}

	public function decrypt_data( $bundle ) {
		$data    = false;
		$payload = false;

		try {
			$payload = Wprus_Crypto::decrypt(
				$bundle,
				self::$encryption_settings['aes_key'],
				self::$encryption_settings['hmac_key']
			);
		} catch ( Exception $e ) {
			Wprus_Logger::log( 'Could not decrypt data: ' . $e->getMessage(), 'alert', 'db_log' );
		}

		if ( $payload ) {
			$data = json_decode( $payload, true );
		} else {
			Wprus_Logger::log( __( 'Could not decrypt data: invalid bundle.', 'wprus' ) );
		}

		return $data;
	}

	public function init() {
		self::$settings_class = get_class( $this->settings );

		if ( ! isset( self::$encryption_settings ) ) {
			self::$encryption_settings = self::$settings_class::get_option( 'encryption' );
		}

		if ( ! isset( self::$ip_whitelist ) ) {
			$ip_whitelist = self::$settings_class::get_option( 'ip_whitelist' );

			if ( ! empty( $ip_whitelist ) ) {
				self::$ip_whitelist = array_filter( array_map( 'trim', explode( "\n", $ip_whitelist ) ) );
			} else {
				self::$ip_whitelist = false;
			}
		}
	}

	public function handle_token_request() {
		$data       = $this->get_data_post();
		$token_info = false;

		if ( $data && isset( $data['method'] ) ) {
			$token_info = Wprus_Nonce::create_nonce( true );
		}

		if ( $token_info ) {
			$action_label = isset( $data['action'] ) ? $data['action'] : __( 'Token', 'wprus' );

			if ( isset( self::$settings_class::$actions[ $action_label ] ) ) {
				$action_label = self::$settings_class::$actions[ $action_label ];
			}

			Wprus_Logger::log(
				// translators: %1$s is the remote site ; %2$s is the action
				sprintf( __( 'Token created: site %1$s - action "%2$s"', 'wprus' ), $data['base_url'], $action_label ),
				'info',
				'db_log'
			);
			wp_send_json( $token_info );
		}

		Wprus_Logger::log( __( 'Failed to create token - invalid payload', 'wprus' ), 'alert', 'db_log' );

		exit();
	}

	public function init_remote_hooks() {
		$data = $this->get_data();

		if ( isset( $data['ping'] ) ) {
			add_action( 'wprus_api_' . $this->endpoint, array( $this, 'handle_ping_notification' ), 10, 0 );
		} else {
			add_action( 'wprus_api_' . $this->endpoint, array( $this, 'handle_request' ), 10, 0 );
		}

		add_action( 'wprus_api_token', array( $this, 'handle_token_request' ), 10, 0 );
	}

	public function init_remote_hooks_authorization() {
		add_action( 'wprus_api_' . $this->endpoint, array( $this, 'authorize_notification' ), 5, 0 );
	}

	public function init_role_handler( $role_api ) {
		$this->role_handler = $role_api;
	}

	public function init_password_handler( $password_api ) {
		$this->password_handler = $password_api;
	}

	public function handle_request() {
		$data = $this->get_data();

		if ( method_exists( $this, 'handle_notification' ) ) {
			do_action( 'wprus_before_handle_action_notification', $this->endpoint, $data );

			$result = $this->handle_notification();

			do_action( 'wprus_after_handle_action_notification', $this->endpoint, $data, $result );
		}
	}

	public function authorize_notification() {
		$token_filter         = FILTER_SANITIZE_STRING;
		$token                = filter_input( INPUT_GET, 'token', $token_filter );
		$token                = ( ! $token ) ? filter_input( INPUT_POST, 'token', $token_filter ) : $token;
		$is_authorized_remote = false;
		$origin               = false;
		$remote_data          = $this->get_data();

		if ( isset( $remote_data['base_url'] ) ) {
			$origin = $remote_data['base_url'];
		}

		if ( 'get' === $this->method ) {
			Wprus_Nonce::init( true, false, self::$encryption_settings['token_expiry'] );
		}

		if ( ! Wprus_Nonce::validate_nonce( $token ) ) {
			$message = __( 'Unauthorized access (invalid token)', 'wprus' );
		} else {

			if ( 'get' === $this->method ) {
				$is_authorized_remote = true;
			} elseif ( 'post' === $this->method ) {

				if ( self::$ip_whitelist ) {
					$is_authorized_remote = in_array( $_SERVER['REMOTE_ADDR'], self::$ip_whitelist, true );
				} else {
					$is_authorized_remote = true;
				}
			}

			if ( $is_authorized_remote ) {
				$message = __( 'Access granted', 'wprus' );
			} elseif ( 'post' === $this->method && self::$ip_whitelist ) {
				// translators: %s is the remote IP address
				$message = sprintf( __( 'Unauthorized access (invalid remote IP address %s)', 'wprus' ), $_SERVER['REMOTE_ADDR'] );
			} else {
				$message = __( 'Unauthorized access (invalid method)', 'wprus' );
			}
		}

		if ( 'get' === $this->method ) {
			Wprus_Nonce::init( false, false, self::$encryption_settings['token_expiry'] );
		}

		$message .= ( $origin ) ? ' - ' . $origin : '';
		$log_type = ( $is_authorized_remote ) ? 'success' : 'alert';

		Wprus_Logger::log( $message, $log_type, 'db_log' );

		if ( ! $is_authorized_remote ) {
			do_action( 'wprus_unauthorized_access', $this->endpoint, $remote_data, $token, $this );

			if ( isset( $remote_data['ping'] ) ) {
				$message = __( 'The remote website encountered the following error: ', 'wprus' ) . $message;

				wp_send_json_error( $message );
			}

			exit();
		}

		do_action( 'wprus_authorized_access', $this->endpoint, $remote_data, $token, $this );
	}

	public function init_local_hooks() {
		$init_notification_hooks = apply_filters( 'wprus_init_notification_hooks', true );

		if ( ! $init_notification_hooks ) {

			return;
		}

		if ( method_exists( $this, 'init_notification_hooks' ) ) {
			$this->init_notification_hooks();
		}
	}

	public function has_remote_async_actions() {

		return false;
	}

	public function init_async_hooks() {
		$init_notification_hooks = apply_filters( 'wprus_init_notification_hooks', true );

		if ( ! $init_notification_hooks ) {

			return;
		}

		if ( ! has_action( 'wp_footer', array( $this, 'fire_remote_async_actions' ) ) ) {
			add_action( 'wp_footer', array( $this, 'fire_remote_async_actions' ), PHP_INT_MIN, 0 );
		}

		if ( ! has_action( 'admin_footer', array( $this, 'fire_remote_async_actions' ) ) ) {
			add_action( 'admin_footer', array( $this, 'fire_remote_async_actions' ), PHP_INT_MIN, 0 );
		}

		if ( ! has_action( 'login_footer', array( $this, 'fire_remote_async_actions' ) ) ) {
			add_action( 'login_footer', array( $this, 'fire_remote_async_actions' ), PHP_INT_MIN, 0 );
		}

		if ( ! has_action( 'init', array( $this, 'set_pending_async_actions_user_id' ) ) ) {
			add_action( 'init', array( $this, 'set_pending_async_actions_user_id' ), 10, 0 );
		}

		if ( ! has_action( 'shutdown', array( $this, 'save_async_actions' ) ) ) {
			add_action( 'shutdown', array( $this, 'save_async_actions' ), 10, 0 );
		}
	}

	public function init_data() {
		$data_get     = filter_input( INPUT_GET, 'wprusdata', FILTER_SANITIZE_STRING );
		$data_post    = filter_input( INPUT_POST, 'wprusdata', FILTER_SANITIZE_STRING );
		$this->method = ( $data_post ) ? 'post' : 'get';
		$this->data   = array(
			'get'  => ( $data_get ) ? $this->decrypt_data( $data_get ) : null,
			'post' => ( $data_post ) ? $this->decrypt_data( $data_post ) : null,
		);
	}

	public function add_action_endpoints( $endpoints ) {
		$endpoints = array_merge(
			$endpoints,
			$this->get_endpoints()
		);

		return $endpoints;
	}

	public function get_active_site_for_action( $endpoint, $site_url = false, $direction = 'incoming' ) {
		$site_url = ( $site_url ) ? $site_url : get_option( 'home' );
		$site     = $this->settings->get_site( $site_url, $endpoint, $direction );

		return $site;
	}

	public function handle_ping_notification() {
		$data = $this->get_data_post();

		if ( empty( $data ) ) {
			Wprus_Logger::log( __( 'A ping was received but the initiator did not use matching encryption and signature action keys', 'wprus' ), 'info', 'db_log' );
			wp_send_json_error( __( 'Mismatch Encryption settings - please make sure the action keys are correctly configured on both sites.', 'wprus' ) );
		}

		if (
			empty( $data['base_url'] ) ||
			empty( $data['direction'] ) ||
			(
				'incoming' !== $data['direction'] &&
				'outgoing' !== $data['direction']
			)
		) {
			Wprus_Logger::log( __( 'A ping was received but the initiator did not send the proper data.', 'wprus' ), 'info', 'db_log' );
			wp_send_json_error( __( 'Malformed data - the test request was received but the data could not be extracted.', 'wprus' ) );
		}

		$site            = $this->get_active_site_for_action( $this->endpoint, $data['base_url'], $data['direction'] );
		$action_label    = $this->endpoint;
		$direction_label = ( 'incoming' === $data['direction'] ) ? __( 'incoming', 'wprus' ) : __( 'outgoing', 'wprus' );
		$remote_addr     = $_SERVER['REMOTE_ADDR'];

		if ( isset( self::$settings_class::$actions[ $action_label ] ) ) {
			$action_label = self::$settings_class::$actions[ $action_label ];
		}

		if ( $site ) {
			do_action( 'wprus_ping_success', $this->endpoint, $data, $remote_addr );

			// translators: %1$s is the sync action name, %2$s is the remote site URL, %3$s is the remote IP, %4$s is the direction
			$message = __( 'Ping received for activated action "%1$s" from %2$s with remote IP %3$s (%4$s)', 'wprus' );

			Wprus_Logger::log(
				sprintf(
					$message,
					$action_label,
					$data['base_url'],
					$remote_addr,
					$direction_label
				),
				'success',
				'db_log'
			);

			// translators: %1$s is the sync action name, %2$s is the current site URL, %3$s is the remote IP, %4$s is the direction
			$message = __( 'Ping success for action "%1$s" from %2$s with remote IP %3$s (%4$s)', 'wprus' );

			wp_send_json_success(
				sprintf(
					$message,
					$action_label,
					get_option( 'home' ),
					$remote_addr,
					$direction_label
				)
			);
		}

		do_action( 'wprus_ping_failure', $this->endpoint, $data, $remote_addr );

		// translators: %1$s is the sync action name, %2$s is the remote site URL, %3$s is the remote IP, %4$s is the direction
		$message = __( 'Ping received for deactivated action "%1$s" from %2$s with remote IP %3$s (%4$s)', 'wprus' );

		Wprus_Logger::log(
			sprintf(
				$message,
				$action_label,
				$data['base_url'],
				$remote_addr,
				$direction_label
			),
			'warning',
			'db_log'
		);

		// translators: %1$s is the sync action name, %2$s is the current site URL, %3$s is the remote IP, %4$s is the direction
		$message = __( '"%1$s" action is not activated on %2$s with remote IP %3$s (%4$s).', 'wprus' );
		$message = sprintf(
			$message,
			$action_label,
			get_option( 'home' ),
			$remote_addr,
			$direction_label
		);

		wp_send_json_error( $message );
	}

	public function notify_ping_remote() {
		$url          = filter_input( INPUT_POST, 'site_url', FILTER_VALIDATE_URL );
		$direction    = filter_input( INPUT_POST, 'direction', FILTER_SANITIZE_STRING );
		$data         = filter_input( INPUT_POST, 'data', FILTER_SANITIZE_STRING, FILTER_REQUIRE_ARRAY );
		$success      = false;
		$payload      = false;
		$default_data = array(
			'ping'      => 1,
			'direction' => ( 'incoming' === $direction ) ? 'outgoing' : 'incoming',
		);

		if ( $data ) {
			$data = array_merge( $default_data, $data );
		} else {
			$data = $default_data;
		}

		$response = $this->fire_action(
			$url,
			$data,
			true,
			5
		);

		do_action( 'wprus_ping_fired', $this->endpoint, $data, $response );

		if ( 200 === $response['response_code'] ) {
			$data = json_decode( $response['body'], true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				$payload = json_last_error_msg();
			}

			if ( $data['success'] ) {
				$success = true;
				$payload = $data['data'];
			}

			$payload = $data['data'];
		}

		if ( ! $success && ! $payload ) {
			$payload = __( 'Error contacting the remote site: ', 'wprus' );

			if (
				! empty( $response['response_code'] ) &&
				200 !== $response['response_code'] &&
				! empty( $response['response_message'] )
			) {
				$payload .= $response['response_code'] . ' - ' . $response['response_message'];
			} else {
				$payload .= __( 'an undefined error occured. Please make sure the address is correct and try again.', 'wprus' );
				$payload .= "\n";
				$payload .= __( 'On the remote site, please make sure the plugin is activated and that the permalinks are up to date by visiting the permalinks settings page.', 'wprus' );
			}

			if ( 404 === absint( $response['response_code'] ) ) {
				$payload .= "\n";
				$payload .= __( 'On the remote site, please make sure the permalinks are not using the "Plain" option.', 'wprus' );
			}
		}

		$log_type = ( $success ) ? 'success' : 'alert';

		Wprus_Logger::log( $payload, $log_type, 'db_log' );

		if ( $success ) {
			wp_send_json_success( $payload );
		}

		wp_send_json_error( $payload );
	}

	public function set_pending_async_actions_user_id() {

		if ( 0 !== get_current_user_id() ) {
			$this->async_user_id = get_current_user_id();

			$this->setcookie(
				'wprus_user_pending_async_actions',
				$this->encrypt_data( get_current_user_id() )
			);
		}
	}

	public function add_remote_async_action( $url, $data ) {
		$data['base_url'] = get_option( 'home' );
		$data['url']      = $url;

		$this->remote_async_actions[] = $data;
	}


	public function save_async_actions() {

		if ( ! empty( $this->remote_async_actions ) && $this->async_user_id ) {
			update_user_meta(
				$this->async_user_id,
				'wprus_' . $this->endpoint . '_pending_async_actions',
				$this->remote_async_actions
			);
		}
	}

	public function fire_remote_async_actions() {
		$location_headers = preg_grep( '/^Location:/i', headers_list() );

		if ( ! empty( $location_headers ) ) {

			return;
		}

		if ( ! empty( $this->remote_async_actions ) ) {
			$user_id = get_current_user_id();
			$actions = $this->remote_async_actions;
		} else {

			if ( is_user_logged_in() ) {
				$user_id = get_current_user_id();
			} else {
				$cookie  = filter_input( INPUT_COOKIE, 'wprus_user_pending_async_actions' );
				$user_id = ( $cookie ) ? $this->decrypt_data( $cookie ) : false;
			}

			$user    = ( $user_id ) ? get_user_by( 'ID', $user_id ) : false;
			$actions = ( $user ) ? get_user_meta( $user->ID, 'wprus_' . $this->endpoint . '_pending_async_actions', true ) : false;
		}

		if ( $actions && $user_id ) {
			$output = '';

			do_action( 'wprus_before_firing_async_actions', $this->endpoint, $actions );

			foreach ( $actions as $action_index => $data ) {
				$url       = trailingslashit( $data['url'] );
				$async_url = $url . 'wprus/' . trailingslashit( $this->endpoint );

				unset( $data['url'] );

				$args       = array(
					'wprusdata' => rawurlencode( $this->encrypt_data( $data ) ),
					'token'     => rawurlencode( $this->get_token( $url, $data['username'], 'get' ) ),
				);
				$script_url = add_query_arg( $args, $async_url );
				$output    .= '<script async src="' . $script_url . '"></script>'; // @codingStandardsIgnoreLine

				Wprus_Logger::log(
					sprintf(
						// translators: %1$s is the url of the script ; %2$s is the action called
						__( 'Added %1$s async URL in %2$s', 'wprus' ),
						$async_url,
						current_filter()
					),
					'info',
					'db_log'
				);
			}

			echo $output; // WPCS XSS OK
			do_action( 'wprus_after_firing_async_actions', $this->endpoint, $actions );
			delete_user_meta( $user_id, 'wprus_' . $this->endpoint . '_pending_async_actions' );

			$this->remote_async_actions = array();
		}
	}

	public function fire_action( $url, $data, $blocking = false, $timeout = 1, $endpoint = null ) {
		$data['base_url'] = get_option( 'home' );
		$endpoint         = $endpoint ? $endpoint : $this->endpoint;

		do_action( 'wprus_before_firing_action', $endpoint, $url, $data );

		$body     = array(
			'wprusdata' => $this->encrypt_data( $data ),
			'token'     => $this->get_token( $url, $data['username'], 'post' ),
		);
		$response = wp_remote_post(
			trailingslashit( $url ) . 'wprus/' . trailingslashit( $endpoint ),
			array(
				'body'     => $body,
				'blocking' => $blocking,
				'compress' => true,
				'timeout'  => $timeout,
			)
		);
		$headers  = wp_remote_retrieve_headers( $response );

		if ( ! empty( $headers ) ) {
			$headers = $headers->getAll();
		}

		do_action( 'wprus_after_firing_action', $endpoint, $url, $data, $response );

		return array(
			'headers'          => $headers,
			'response_code'    => wp_remote_retrieve_response_code( $response ),
			'response_message' => wp_remote_retrieve_response_message( $response ),
			'body'             => wp_remote_retrieve_body( $response ),
		);
	}

	/*******************************************************************
	 * Protected methods
	 *******************************************************************/

	protected function get_token( $url, $username, $method = 'post' ) {
		$user        = get_user_by( 'login', $username );
		$tokens_info = ( $user ) ? get_user_meta( $user->ID, 'wprus_api_tokens', true ) : null;
		$tokens_info = ( null !== $tokens_info && ! $tokens_info ) ? array() : $tokens_info;

		if ( null === $tokens_info ) {

			return false;
		}

		if ( isset( $tokens_info[ $url ] ) && 'get' === $method ) {
			unset( $tokens_info[ $url ] );
		}

		if (
			! isset( $tokens_info[ $url ] ) ||
			$tokens_info[ $url ]['expiry'] <= time() - self::TOKEN_EXPIRY_BUFFER
		) {
			$payload    = array(
				'action'   => $this->endpoint,
				'method'   => $method,
				'base_url' => $url,
			);
			$token_info = $this->get_remote_token( $payload );

			if ( $token_info ) {
				$tokens_info[ $url ] = $token_info;
			} elseif ( isset( $tokens_info[ $url ] ) ) {
				unset( $tokens_info[ $url ] );
			}

			if ( 'post' === $method ) {
				update_user_meta( $user->ID, 'wprus_api_tokens', $tokens_info );
			}
		}

		return isset( $tokens_info[ $url ]['nonce'] ) ? $tokens_info[ $url ]['nonce'] : false;
	}

	protected function get_remote_token( $payload ) {

		if ( self::$can_request_token ) {
			$timeout = apply_filters( 'wprus_request_token_timeout', 1 );

			// translators: %s is the url of the caller
			Wprus_Logger::log( sprintf( __( 'Renewing token for %s', 'wprus' ), $payload['base_url'] ), 'info', 'db_log' );
		} else {
			$timeout = apply_filters( 'wprus_request_token_retry_timeout', 5 );
		}

		$token_info = false;
		$response   = wp_remote_post(
			trailingslashit( $payload['base_url'] ) . 'wprus/token/',
			array(
				'body'     => array(
					'wprusdata' => $this->encrypt_data( $payload ),
				),
				'compress' => true,
				'timeout'  => $timeout,
			)
		);

		$body = wp_remote_retrieve_body( $response );

		if ( $body && 200 === wp_remote_retrieve_response_code( $response ) ) {
			$token_info = json_decode( $body, true );
		} elseif ( self::$can_request_token ) {
			Wprus_Logger::log(
				sprintf(
					// translators: %s is the url of the caller
					__( 'Failed to renew token for %s - retrying...', 'wprus' ),
					$payload['base_url']
				),
				'warning',
				'db_log'
			);

			self::$can_request_token = false;
			$token_info              = $this->get_remote_token( $payload );
		}

		if ( false === $token_info && ! self::$can_request_token ) {
			Wprus_Logger::log(
				sprintf(
					// translators: %s is the url of the caller
					__( 'Failed to renew token for %s', 'wprus' ),
					$payload['base_url']
				),
				'alert',
				'db_log'
			);
		}

		return $token_info;
	}

	protected function setcookie( $name, $value, $expire = 0 ) {

		if ( ! headers_sent() ) {
			setcookie( $name, $value, $expire, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl() );
		} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			headers_sent( $file, $line );
			trigger_error(  // @codingStandardsIgnoreLine
				esc_html( $name . 'cookie cannot be set - headers already sent by ' . $file . 'on line' . $line ),
				E_USER_NOTICE
			);
		}
	}

	protected function get_data_get() {
		$data_get = $this->data['get'];

		return $data_get;
	}

	protected function get_data_post() {
		$data_post = $this->data['post'];

		return $data_post;
	}

	protected function get_data() {
		$data = array();

		if ( ! empty( $this->data['get'] ) && is_array( $this->data['get'] ) ) {
			$data = array_merge( $data, $this->data['get'] );
		} elseif ( ! empty( $this->data['get'] ) ) {
			$data[] = $this->data['get'];
		}

		if ( ! empty( $this->data['post'] ) && is_array( $this->data['post'] ) ) {
			$data = array_merge( $data, $this->data['post'] );
		} elseif ( ! empty( $this->data['post'] ) ) {
			$data[] = $this->data['post'];
		}

		return $data;
	}

	protected function validate( $data ) {
		$valid =
			isset( $data['username'] ) &&
			! empty( $data['username'] ) &&
			isset( $data['base_url'] ) &&
			! empty( $data['base_url'] );

		return $valid;
	}

	protected function sanitize( $data ) {

		return $data;
	}

	protected function get_endpoints() {

		return array(
			$this->endpoint => $this->endpoint . '/?',
		);
	}

}
