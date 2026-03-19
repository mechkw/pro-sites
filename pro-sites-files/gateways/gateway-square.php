<?php
/**
 * Pro Sites (Gateway: Square Payment Gateway).
 *
 * Phase 1 scaffold: registration, settings, install table, and safe placeholders.
 */

class ProSites_Gateway_Square {

	/**
	 * Table for Square customer/subscription mappings.
	 *
	 * @var string
	 */
	public static $table;

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
		return 'square';
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
			</table>
		</div>
		<?php
	}

	/**
	 * Create/upgrade Square table.
	 */
	private function install() {
		global $wpdb;

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
	 * Placeholder while checkout integration is built.
	 */
	public static function render_gateway( $render_data = array(), $args = array(), $blog_id = 0, $domain = '' ) {
		$content  = '<div class="psts-square-gateway">';
		$content .= '<p><strong>' . esc_html__( 'Square checkout is being set up.', 'psts' ) . '</strong></p>';
		$content .= '<p>' . esc_html__( 'This gateway is in staged development. Use another active gateway until Square checkout is enabled.', 'psts' ) . '</p>';
		$content .= '</div>';

		return $content;
	}

	/**
	 * Placeholder process hook for checkout render cycle.
	 */
	public static function process_on_render() {
		return true;
	}

	/**
	 * Placeholder process hook.
	 */
	public static function process_checkout_form( $process_data = array(), $blog_id = 0, $domain = '' ) {
		return false;
	}

	/**
	 * Placeholder existing user info method.
	 */
	public static function get_existing_user_information( $blog_id, $domain, $get_all = true ) {
		return array();
	}

	/**
	 * Supported currencies for initial Square rollout.
	 *
	 * @return array
	 */
	public static function get_supported_currencies() {
		return array( 'USD' );
	}
}
