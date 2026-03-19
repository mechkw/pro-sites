<?php
/**
 * Pro Sites (Gateway: Square Payment Gateway).
 *
 * Phase 2 implementation:
 * - Web Payments token capture (source_id)
 * - customer create/find
 * - card-on-file create
 * - subscription create (using configured plan variation ids)
 * - Pro Sites extend path
 */

class ProSites_Gateway_Square {

	/**
	 * Gateway id.
	 *
	 * @var string
	 */
	private static $id = 'square';

	/**
	 * Table for Square customer/subscription mappings.
	 *
	 * @var string
	 */
	public static $table;

	/**
	 * Runtime state.
	 */
	private static $blog_id = 0;
	private static $level   = 0;
	private static $period  = 0;
	private static $domain  = '';
	private static $email   = '';
	private static $show_completed = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		global $wpdb;

		self::$table = $wpdb->base_prefix . 'pro_sites_square_customers';

		$this->install();
	}

	/**
	 * Gateway slug used by Pro Sites.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return self::$id;
	}

	/**
	 * Gateway display name map used by Pro Sites.
	 *
	 * @return array
	 */
	public static function get_name() {
		return array(
			'square' => __( 'Square', 'psts' ),
		);
	}

	/**
	 * Settings view for Square gateway.
	 */
	public function settings() {
		global $psts;
		?>
		<div class="inside">
			<table class="form-table">
				<tr>
					<th scope="row" class="psts-help-div">
						<?php echo __( 'Sandbox Mode', 'psts' ) . $psts->help_text( __( 'Enable Square Sandbox for testing. Keep this ON until production go-live.', 'psts' ) ); ?>
					</th>
					<td>
						<label>
							<input type="checkbox" name="psts[square_sandbox]" <?php checked( $psts->get_setting( 'square_sandbox', '1' ), '1' ); ?> value="1" />
							<?php _e( 'Use Square Sandbox', 'psts' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row" class="psts-help-div">
						<?php echo __( 'Square Application ID', 'psts' ) . $psts->help_text( __( 'Used by Square Web Payments SDK on checkout.', 'psts' ) ); ?>
					</th>
					<td>
						<input type="text" style="width:100%;" name="psts[square_application_id]" value="<?php echo esc_attr( $psts->get_setting( 'square_application_id', '' ) ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row" class="psts-help-div">
						<?php echo __( 'Square Access Token', 'psts' ) . $psts->help_text( __( 'Sandbox or production access token, depending on mode.', 'psts' ) ); ?>
					</th>
					<td>
						<input type="password" style="width:100%;" name="psts[square_access_token]" value="<?php echo esc_attr( $psts->get_setting( 'square_access_token', '' ) ); ?>" autocomplete="off" />
					</td>
				</tr>
				<tr>
					<th scope="row" class="psts-help-div">
						<?php echo __( 'Square Location ID', 'psts' ) . $psts->help_text( __( 'Location used for card processing and subscriptions.', 'psts' ) ); ?>
					</th>
					<td>
						<input type="text" style="width:100%;" name="psts[square_location_id]" value="<?php echo esc_attr( $psts->get_setting( 'square_location_id', '' ) ); ?>" />
					</td>
				</tr>
				<tr>
					<th scope="row" class="psts-help-div">
						<?php echo __( 'Square Webhook Signature Key', 'psts' ) . $psts->help_text( __( 'Used to validate incoming Square webhook signatures.', 'psts' ) ); ?>
					</th>
					<td>
						<input type="password" style="width:100%;" name="psts[square_webhook_signature_key]" value="<?php echo esc_attr( $psts->get_setting( 'square_webhook_signature_key', '' ) ); ?>" autocomplete="off" />
					</td>
				</tr>
				<tr>
					<th scope="row" class="psts-help-div">
						<?php echo __( 'Square Plan Variation Map (JSON)', 'psts' ) . $psts->help_text( __( 'Map Pro Sites level/period to Square plan variation IDs. Example: {"1_1":"PLAN_VARIATION_ID","2_12":"PLAN_VARIATION_ID"}', 'psts' ) ); ?>
					</th>
					<td>
						<textarea style="width:100%;min-height:90px;" name="psts[square_plan_variation_map]"><?php echo esc_textarea( $psts->get_setting( 'square_plan_variation_map', '' ) ); ?></textarea>
					</td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Create/upgrade Square table.
	 */
	private function install() {
		$table = self::$table;
		$sql   = "CREATE TABLE $table (
			blog_id bigint(20) NOT NULL,
			customer_id varchar(64) NOT NULL,
			subscription_id varchar(64) NULL,
			card_id varchar(64) NULL,
			PRIMARY KEY  (blog_id),
			UNIQUE KEY ix_subscription_id (subscription_id)
		) DEFAULT CHARSET=utf8;";

		if ( ! defined( 'DO_NOT_UPGRADE_GLOBAL_TABLES' ) || ! DO_NOT_UPGRADE_GLOBAL_TABLES ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
		}
	}

	/**
	 * Render gateway form and Square Web Payments tokenization helper.
	 */
	public static function render_gateway( $render_data = array(), $args = array(), $blog_id = 0, $domain = '' ) {
		global $psts;

		self::$blog_id = (int) $blog_id;
		self::$domain  = $domain;

		$session_keys = array( 'new_blog_details', 'upgraded_blog_details' );
		foreach ( $session_keys as $key ) {
			$render_data[ $key ] = isset( $render_data[ $key ] ) ? $render_data[ $key ] : ProSites_Helper_Session::session( $key );
		}

		$period = isset( $args['period'] ) && ! empty( $args['period'] ) ? (int) $args['period'] : ProSites_Helper_ProSite::default_period();
		$level  = isset( $render_data['new_blog_details']['level'] ) ? (int) $render_data['new_blog_details']['level'] : 0;
		$level  = isset( $render_data['upgraded_blog_details']['level'] ) ? (int) $render_data['upgraded_blog_details']['level'] : $level;

		$app_id      = trim( $psts->get_setting( 'square_application_id', '' ) );
		$location_id = trim( $psts->get_setting( 'square_location_id', '' ) );
		$sandbox     = (string) $psts->get_setting( 'square_sandbox', '1' ) === '1';
		$sdk_url     = $sandbox ? 'https://sandbox.web.squarecdn.com/v1/square.js' : 'https://web.squarecdn.com/v1/square.js';

		if ( empty( $app_id ) || empty( $location_id ) ) {
			return '<div id="psts-general-error" class="psts-error">' . esc_html__( 'Square is not configured yet. Please set Application ID and Location ID in gateway settings.', 'psts' ) . '</div>';
		}

		$checkout_url = $psts->checkout_url( $blog_id );
		$form_id      = 'psts-square-checkout-form';
		$card_id      = 'psts-square-card';
		$nonce_id     = 'psts-square-source-id';
		$error_id     = 'psts-square-error';
		$button_id    = 'psts-square-submit';

		$content  = '<form id="' . esc_attr( $form_id ) . '" action="' . esc_url( $checkout_url ) . '" method="post">';
		$content .= '<input type="hidden" name="psts_square_checkout" value="1" />';
		$content .= '<input type="hidden" name="level" value="' . (int) $level . '" />';
		$content .= '<input type="hidden" name="period" value="' . (int) $period . '" />';
		$content .= '<input type="hidden" name="square_source_id" id="' . esc_attr( $nonce_id ) . '" value="" />';
		$content .= '<div id="psts-square-gateway">';
		$content .= '<h3>' . esc_html__( 'Pay with Card (Square)', 'psts' ) . '</h3>';
		$content .= '<div id="' . esc_attr( $card_id ) . '"></div>';
		$content .= '<p id="' . esc_attr( $error_id ) . '" class="psts-error" style="display:none;"></p>';
		$content .= '<p><button type="submit" id="' . esc_attr( $button_id ) . '">' . esc_html__( 'Pay Securely', 'psts' ) . '</button></p>';
		$content .= '</div></form>';

		$content .= '<script src="' . esc_url( $sdk_url ) . '"></script>';
		$content .= '<script>';
		$content .= '(function(){';
		$content .= 'var form=document.getElementById(' . wp_json_encode( $form_id ) . ');';
		$content .= 'if(!form||!window.Square){return;}';
		$content .= 'var appId=' . wp_json_encode( $app_id ) . ';';
		$content .= 'var locationId=' . wp_json_encode( $location_id ) . ';';
		$content .= 'var nonceInput=document.getElementById(' . wp_json_encode( $nonce_id ) . ');';
		$content .= 'var errorEl=document.getElementById(' . wp_json_encode( $error_id ) . ');';
		$content .= 'var submitBtn=document.getElementById(' . wp_json_encode( $button_id ) . ');';
		$content .= 'var card;';
		$content .= 'async function init(){try{var payments=window.Square.payments(appId,locationId);card=await payments.card();await card.attach(' . wp_json_encode( '#' . $card_id ) . ');}catch(e){if(errorEl){errorEl.style.display="block";errorEl.textContent="Square card form failed to load.";}}}';
		$content .= 'form.addEventListener("submit",async function(ev){if(nonceInput&&nonceInput.value){return;}ev.preventDefault();submitBtn.disabled=true;try{var result=await card.tokenize();if(result.status!=="OK"){throw new Error("tokenize_failed");}nonceInput.value=result.token;form.submit();}catch(e){submitBtn.disabled=false;if(errorEl){errorEl.style.display="block";errorEl.textContent="We could not verify your card details. Please try again.";}}});';
		$content .= 'init();';
		$content .= '})();';
		$content .= '</script>';

		return $content;
	}

	/**
	 * Process checkout postback.
	 */
	public static function process_checkout_form( $process_data = array(), $blog_id = 0, $domain = '' ) {
		global $psts;

		if ( 1 !== (int) self::from_request( 'psts_square_checkout', 0 ) ) {
			return false;
		}

		$session_keys = array( 'new_blog_details', 'upgraded_blog_details', 'COUPON_CODE', 'activation_key' );
		foreach ( $session_keys as $key ) {
			$process_data[ $key ] = isset( $process_data[ $key ] ) ? $process_data[ $key ] : ProSites_Helper_Session::session( $key );
		}

		self::$blog_id = empty( $blog_id ) ? (int) self::from_request( 'bid', 0, 'get' ) : (int) $blog_id;
		self::$domain  = $domain;
		self::$level   = (int) self::from_request( 'level' );
		self::$period  = (int) self::from_request( 'period' );
		self::$email   = self::get_email( $process_data );

		if ( empty( self::$level ) || empty( self::$period ) ) {
			$psts->errors->add( 'square', __( 'Please choose your desired level and payment plan.', 'psts' ) );
			return false;
		}

		if ( empty( self::$email ) ) {
			$psts->errors->add( 'square', __( 'No valid email found for this checkout.', 'psts' ) );
			return false;
		}

		$customer = self::get_or_create_customer( self::$email, self::$blog_id );
		if ( empty( $customer['id'] ) ) {
			$psts->errors->add( 'square', __( 'Unable to create/find Square customer.', 'psts' ) );
			return false;
		}

		$source_id = self::from_request( 'square_source_id', '' );
		$db_row    = self::get_db_customer( self::$blog_id );
		$card_id   = ! empty( $db_row->card_id ) ? $db_row->card_id : '';

		if ( ! empty( $source_id ) ) {
			$card = self::create_card_on_file( $customer['id'], $source_id );
			if ( empty( $card['id'] ) ) {
				$psts->errors->add( 'square', __( 'Unable to save card in Square.', 'psts' ) );
				return false;
			}
			$card_id = $card['id'];
		}

		if ( empty( $card_id ) ) {
			$psts->errors->add( 'square', __( 'No Square card was available for this checkout.', 'psts' ) );
			return false;
		}

		$plan_variation_id = self::get_plan_variation_id( self::$level, self::$period );
		if ( empty( $plan_variation_id ) ) {
			$psts->errors->add( 'square', __( 'Square plan mapping is missing. Set Square Plan Variation Map in gateway settings.', 'psts' ) );
			return false;
		}

		$subscription = self::create_subscription( $customer['id'], $card_id, $plan_variation_id );
		if ( empty( $subscription['id'] ) ) {
			$psts->errors->add( 'square', __( 'Square subscription could not be created.', 'psts' ) );
			return false;
		}

		self::set_db_customer( self::$blog_id, $customer['id'], $subscription['id'], $card_id );

		$amount = $psts->get_level_setting( self::$level, 'price_' . self::$period );
		$expire = ! empty( $subscription['charged_through_date'] ) ? strtotime( $subscription['charged_through_date'] . ' 23:59:59' ) : false;

		if ( ! empty( self::$blog_id ) ) {
			$psts->extend(
				self::$blog_id,
				self::$period,
				self::get_slug(),
				self::$level,
				$amount,
				$expire,
				true
			);
		}

		self::$show_completed = true;
		return true;
	}

	/**
	 * Process form on render cycle.
	 *
	 * @return bool
	 */
	public static function process_on_render() {
		return true;
	}

	/**
	 * Existing user information for UI use.
	 */
	public static function get_existing_user_information( $blog_id, $domain, $get_all = true ) {
		$row = self::get_db_customer( (int) $blog_id );
		if ( empty( $row ) ) {
			return array();
		}

		return array(
			'customer_id'     => $row->customer_id,
			'subscription_id' => $row->subscription_id,
			'card_id'         => $row->card_id,
		);
	}

	/**
	 * Supported currencies for initial Square rollout.
	 *
	 * @return array
	 */
	public static function get_supported_currencies() {
		return array( 'USD' );
	}

	/**
	 * Get map-based plan variation id for level+period.
	 *
	 * @param int $level
	 * @param int $period
	 *
	 * @return string
	 */
	private static function get_plan_variation_id( $level, $period ) {
		global $psts;

		$raw = trim( $psts->get_setting( 'square_plan_variation_map', '' ) );
		if ( empty( $raw ) ) {
			return '';
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return '';
		}

		$key = $level . '_' . $period;
		return isset( $decoded[ $key ] ) ? trim( $decoded[ $key ] ) : '';
	}

	/**
	 * Find or create a Square customer.
	 *
	 * @param string $email
	 * @param int    $blog_id
	 *
	 * @return array
	 */
	private static function get_or_create_customer( $email, $blog_id ) {
		if ( ! empty( $blog_id ) ) {
			$row = self::get_db_customer( $blog_id );
			if ( ! empty( $row->customer_id ) ) {
				return array( 'id' => $row->customer_id );
			}
		}

		$search = self::api_request( 'POST', '/customers/search', array(
			'query' => array(
				'filter' => array(
					'email_address' => array(
						'exact' => $email,
					),
				),
			),
		) );

		if ( ! empty( $search['customers'][0]['id'] ) ) {
			return array( 'id' => $search['customers'][0]['id'] );
		}

		$create = self::api_request( 'POST', '/customers', array(
			'email_address' => $email,
			'reference_id'  => ! empty( $blog_id ) ? 'blog_' . $blog_id : 'blog_unknown',
		) );

		if ( ! empty( $create['customer']['id'] ) ) {
			return array( 'id' => $create['customer']['id'] );
		}

		return array();
	}

	/**
	 * Create a card on file from a web payment token/source id.
	 *
	 * @param string $customer_id
	 * @param string $source_id
	 *
	 * @return array
	 */
	private static function create_card_on_file( $customer_id, $source_id ) {
		global $psts;

		$result = self::api_request( 'POST', '/cards', array(
			'source_id'       => $source_id,
			'idempotency_key' => self::idempotency_key( 'card' ),
			'card'            => array(
				'customer_id' => $customer_id,
			),
		) );

		if ( ! empty( $result['card']['id'] ) ) {
			return $result['card'];
		}

		return array();
	}

	/**
	 * Create Square subscription.
	 *
	 * @param string $customer_id
	 * @param string $card_id
	 * @param string $plan_variation_id
	 *
	 * @return array
	 */
	private static function create_subscription( $customer_id, $card_id, $plan_variation_id ) {
		$result = self::api_request( 'POST', '/subscriptions', array(
			'idempotency_key'            => self::idempotency_key( 'sub' ),
			'location_id'                => self::location_id(),
			'plan_variation_id'          => $plan_variation_id,
			'customer_id'                => $customer_id,
			'card_id'                    => $card_id,
			'timezone'                   => 'UTC',
			'start_date'                 => gmdate( 'Y-m-d' ),
		) );

		if ( ! empty( $result['subscription'] ) ) {
			return $result['subscription'];
		}

		return array();
	}

	/**
	 * Wrapper for Square API requests.
	 *
	 * @param string $method
	 * @param string $path
	 * @param array  $body
	 *
	 * @return array
	 */
	private static function api_request( $method, $path, $body = array() ) {
		global $psts;

		$token = trim( $psts->get_setting( 'square_access_token', '' ) );
		if ( empty( $token ) ) {
			return array();
		}

		$base_url = self::base_url();
		$url      = $base_url . '/v2' . $path;

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'Square-Version'=> '2024-12-18',
			),
		);

		if ( ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array();
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		if ( $code >= 400 ) {
			return array();
		}

		return $data;
	}

	/**
	 * Get db row by blog id.
	 *
	 * @param int $blog_id
	 *
	 * @return object|null
	 */
	private static function get_db_customer( $blog_id ) {
		global $wpdb;

		if ( empty( $blog_id ) ) {
			return null;
		}

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::$table . " WHERE blog_id = %d", $blog_id ) );
	}

	/**
	 * Upsert db customer row.
	 *
	 * @param int    $blog_id
	 * @param string $customer_id
	 * @param string $subscription_id
	 * @param string $card_id
	 */
	private static function set_db_customer( $blog_id, $customer_id, $subscription_id = '', $card_id = '' ) {
		global $wpdb;

		if ( empty( $blog_id ) || empty( $customer_id ) ) {
			return;
		}

		$wpdb->replace(
			self::$table,
			array(
				'blog_id'         => (int) $blog_id,
				'customer_id'     => (string) $customer_id,
				'subscription_id' => (string) $subscription_id,
				'card_id'         => (string) $card_id,
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Read a value from request.
	 *
	 * @param string $string
	 * @param mixed  $default
	 * @param string $type
	 *
	 * @return mixed
	 */
	private static function from_request( $string, $default = false, $type = 'post' ) {
		switch ( $type ) {
			case 'get':
				$value = isset( $_GET[ $string ] ) ? $_GET[ $string ] : false;
				break;
			case 'request':
				$value = isset( $_REQUEST[ $string ] ) ? $_REQUEST[ $string ] : false;
				break;
			case 'post':
			default:
				$value = isset( $_POST[ $string ] ) ? $_POST[ $string ] : false;
				break;
		}

		return ! empty( $value ) ? $value : $default;
	}

	/**
	 * Resolve checkout email.
	 *
	 * @param array $process_data
	 *
	 * @return string
	 */
	private static function get_email( $process_data = array() ) {
		global $current_user;

		$email = empty( $current_user->user_email ) ? '' : $current_user->user_email;
		if ( empty( $email ) ) {
			$email = self::from_request( 'user_email', '' );
		}
		if ( empty( $email ) ) {
			$email = self::from_request( 'signup_email', '' );
		}
		if ( empty( $email ) ) {
			$email = self::from_request( 'blog_email', '' );
		}
		if ( empty( $email ) && isset( $process_data['new_blog_details']['user_email'] ) ) {
			$email = $process_data['new_blog_details']['user_email'];
		}

		return sanitize_email( $email );
	}

	/**
	 * Generate an idempotency key.
	 *
	 * @param string $prefix
	 *
	 * @return string
	 */
	private static function idempotency_key( $prefix ) {
		return substr( md5( $prefix . '|' . self::$blog_id . '|' . self::$level . '|' . self::$period . '|' . microtime( true ) . '|' . wp_rand() ), 0, 45 );
	}

	/**
	 * Square base URL.
	 *
	 * @return string
	 */
	private static function base_url() {
		global $psts;
		$sandbox = (string) $psts->get_setting( 'square_sandbox', '1' ) === '1';
		return $sandbox ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';
	}

	/**
	 * Square location id.
	 *
	 * @return string
	 */
	private static function location_id() {
		global $psts;
		return trim( $psts->get_setting( 'square_location_id', '' ) );
	}
}

// Register the gateway.
psts_register_gateway(
	'ProSites_Gateway_Square',
	__( 'Square', 'psts' ),
	__( 'Accept card payments with Square.', 'psts' )
);
