<?php
/**
 * Plugin Name: ConcordPay for WP eCommerce
 * Plugin URI: https://pay.concord.ua
 * Description: ConcordPay Payment Gateway for WP eCommerce.
 * Version: 1.0.1
 * Author: ConcordPay
 * Domain Path: /lang
 * Text Domain: concordpay-for-wp-ecommerce
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * WP eCommerce requires at least: 3.0.0
 * WP eCommerce tested up to: 3.15.1
 *
 * @package wp-e-commerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Minimum PHP, WordPress and WP eCommerce versions needs for ConcordPay plugin.
const CONCORDPAY_PHP_VERSION          = 50400;
const CONCORDPAY_WP_VERSION           = '3.5';
const CONCORDPAY_WP_ECOMMERCE_VERSION = '3.0';

add_action( 'admin_init', 'concordpay_check_environment' );

/**
 * Check environment parameters
 *
 * @return bool|array
 */
function concordpay_check_environment() {
	global $wp_version;
	$messages = array();

	if ( PHP_VERSION_ID < CONCORDPAY_PHP_VERSION ) {
		/* translators: 1: Required PHP version, 2: Running PHP version. */
		$messages[] = sprintf( esc_html__( 'The minimum PHP version required for ConcordPay is %1$s. You are running %2$s.', 'concordpay-for-wp-ecommerce' ), '5.4.0', (float) PHP_VERSION );
	}

	if ( version_compare( $wp_version, CONCORDPAY_WP_VERSION, '<' ) ) {
		/* translators: 1: Required WordPress version, 2: Running WordPress version. */
		$messages[] = sprintf( esc_html__( 'The minimum WordPress version required for ConcordPay is %1$s. You are running %2$s.', 'concordpay-for-wp-ecommerce' ), CONCORDPAY_WP_VERSION, $wp_version );
	}

	if ( ! is_plugin_active( 'wp-e-commerce/wp-shopping-cart.php' )
	) {
		$messages[] = esc_html__( 'WP eCommerce needs to be activated.', 'concordpay-for-wp-ecommerce' );
	}

	if ( is_plugin_active( 'wp-e-commerce/wp-shopping-cart.php' ) &&
		version_compare( WPSC_VERSION, CONCORDPAY_WP_ECOMMERCE_VERSION, '<' )
	) {
		/* translators: 1: Required WP eCommerce version, 2: Running WP eCommerce version. */
		$messages[] = sprintf( esc_html__( 'The minimum WP eCommerce version required for ConcordPay is %1$s. You are running %2$s.', 'concordpay-for-wp-ecommerce' ), CONCORDPAY_WP_ECOMMERCE_VERSION, WPSC_VERSION );
	}

	if ( ! empty( $messages ) ) {
		add_action( 'admin_notices', 'concordpay_error_notice', 10, 1 );
		foreach ( $messages as $message ) {
			do_action( 'admin_notices', $message );
		}
		deactivate_plugins( plugin_basename( __FILE__ ) );
		if ( isset( $_GET['activate'] ) ) {
			unset( $_GET['activate'] );
		}
	}

	$concorpday_plugin_file = plugin_basename( __FILE__ );
	if ( is_plugin_active( $concorpday_plugin_file ) ) {
		// Add ConcordPay settings link on Plugins page.
		add_filter( "plugin_action_links_$concorpday_plugin_file", 'concordpay_plugin_settings_link', 10, 1 );
	}

	return false;
}

/**
 * Displays error messages if present.
 *
 * @param string $message Admin error message.
 */
function concordpay_error_notice( $message ) {
	?><div class="error is-dismissible"><p><?php echo $message; ?></p></div>
	<?php
}

require_once __DIR__ . '/concordpay.cls.php';
define( 'CONCORDPAY_IMGDIR', WP_PLUGIN_URL . '/' . plugin_basename( __DIR__ ) . '/assets/img/' );

/*
 * This is the gateway variable $nzshpcrt_gateways, it is used for displaying gateway information on the wp-admin pages and also
 * for internal operations.
 */
$nzshpcrt_gateways[ $num ] = array(
	'name'            => 'ConcordPay',
	'internalname'    => 'concordpay',
	'function'        => 'gateway_concordpay',
	'form'            => 'form_concordpay',
	'submit_function' => 'submit_concordpay',
	'class_name'      => 'ConcordPay',
	'payment_type'    => 'credit_card',
	'display_name'    => 'ConcordPay',
	'image'           => plugin_dir_url( __FILE__ ) . 'assets/img/concordpay.svg',
);

load_plugin_textdomain( 'concordpay-for-wp-ecommerce', false, basename( __DIR__ ) . '/lang' );

// Variables for translate plugin header.
$plugin_name        = esc_html__( 'ConcordPay for WP eCommerce', 'concordpay-for-wp-ecommerce' );
$plugin_description = esc_html__( 'ConcordPay Payment Gateway for WP eCommerce.', 'concordpay-for-wp-ecommerce' );

/**
 * Add ConcordPay settings link on Plugins page.
 *
 * @param array $links Links under the name of the plugin.
 *
 * @return array
 */
function concordpay_plugin_settings_link( $links ) {
	$settings_link = '<a href="' . admin_url( 'options-general.php?page=wpsc-settings&tab=gateway&payment_gateway_id=concordpay' ) . '">' . esc_html__( 'Settings', 'concordpay-for-wp-ecommerce' ) . '</a>';
	array_unshift( $links, $settings_link );

	return $links;
}

/**
 * Prepare and send payment form to payment gateway
 *
 * @param string $separator Separator.
 * @param string $sessionid Session ID.
 */
function gateway_concordpay( $separator, $sessionid ) {
	global $wpdb;

	$sessionid    = sanitize_text_field( $sessionid );
	$purchase_log = new WPSC_Purchase_Log( $sessionid, 'sessionid' );
	if ( ! $purchase_log->exists() ) {
		return;
	}

	$cart          = $purchase_log->get_cart_contents();
	$order_id      = $purchase_log->get( 'id' );
	$checkout_data = new WPSC_Checkout_Form_Data( $order_id );
	$transactid    = get_bloginfo() . '_' . md5( 'concordpay_' . $order_id );
	$amount        = (float) $purchase_log->get_total();

	$concordpay_args = array(
		'operation'    => 'Purchase',
		'amount'       => $amount,
		'order_id'     => $order_id,
		'currency_iso' => ConcordPay::CURRENCY_HRYVNA,
		'add_params'   => array(),
		'language'     => ConcordPay::getInst()->get_concordpay_locale(),
	);

	// Default URL.
	$default_url   = get_site_url();
	$result_ques   = "/?concordpay_results=true&sessionid=$sessionid";
	$callback_ques = "/?concordpay_callback=true&sessionid=$sessionid";

	$concordpay_args['approve_url']  = ( ! empty( get_option( 'concordpay_approve_url' ) ) ) ?
		esc_url( get_option( 'concordpay_approve_url' ) ) . $result_ques :
		$default_url . $result_ques;
	$concordpay_args['decline_url']  = ( ! empty( get_option( 'concordpay_decline_url' ) ) ) ?
		esc_url( get_option( 'concordpay_decline_url' ) ) . $result_ques :
		$default_url . $result_ques;
	$concordpay_args['cancel_url']   = ( ! empty( get_option( 'concordpay_cancel_url' ) ) ) ?
		esc_url( get_option( 'concordpay_cancel_url' ) ) . $result_ques :
		$default_url . $result_ques;
	$concordpay_args['callback_url'] = ( ! empty( get_option( 'concordpay_callback_url' ) ) ) ?
		esc_url( get_option( 'concordpay_callback_url' ) ) . $callback_ques :
		$default_url . $callback_ques;

	foreach ( $cart as $item ) {
		$concordpay_args['productName'][]  = $item->name;
		$concordpay_args['productCount'][] = $item->quantity;
		$concordpay_args['productPrice'][] = $item->price;
	}

	$client_fullname = $checkout_data->get( 'billingfirstname' ) . ' ' . $checkout_data->get( 'billinglastname' );
	$client_phone    = str_replace( array( '+', ' ', '(', ')' ), array( '', '', '', '' ), $checkout_data->get( 'billingphone' ) );
	if ( strlen( $client_phone ) === 10 ) {
		$client_phone = '38' . $client_phone;
	} elseif ( strlen( $client_phone ) === 11 ) {
		$client_phone = '3' . $client_phone;
	}

	$concordpay_args['description'] = esc_html__( 'Payment by card on the site', 'concordpay-for-wp-ecommerce' ) .
		' ' . get_bloginfo() . ', ' . $client_fullname . ', ' . $client_phone;

	// Statistics.
	$concordpay_args['client_first_name'] = $checkout_data->get( 'billingfirstname' ) ?? '';
	$concordpay_args['client_last_name']  = $checkout_data->get( 'billinglastname' ) ?? '';
	$concordpay_args['phone']             = $client_phone;
	$concordpay_args['email']             = $checkout_data->get( 'billingemail' ) ?? '';

	$img = WPSC_URL . '/images/indicator.gif';

	$button = "<img style='position:absolute; top:50%; left:47%; margin-top:-125px; margin-left:-60px;' src='$img' alt=''>
	<script>
		function submitConcordPayForm()
		{
			document.getElementById('form_concordpay').submit();
		}
		setTimeout( submitConcordPayForm, 200 );
	</script>";

	$pay = ConcordPay::getInst()->fill_pay_form( $concordpay_args );
	echo $button;
	echo $pay->get_answer();

	$data = array(
		'processed'  => WPSC_Purchase_Log::ORDER_RECEIVED,
		'transactid' => $transactid,
		'date'       => time(),
	);

	$where  = array( 'sessionid' => $sessionid );
	$format = array( '%d', '%s', '%s' );
	$wpdb->update( WPSC_TABLE_PURCHASE_LOGS, $data, $where, $format );

	transaction_results( $sessionid, false, $transactid );

	exit();
}

/**
 * Callback handler.
 */
function concordpay_callback() {
	// Callback url : 'http://yoursite.com/?concordpay_callback=true'.

	if ( ! isset( $_GET['concordpay_callback'], $_GET['sessionid'] ) ||
		empty( $_GET['sessionid'] ) ||
		'true' !== sanitize_text_field( $_GET['concordpay_callback'] )
	) {
		return;
	}

	$data = json_decode( file_get_contents( 'php://input' ), true );
	$data = array_map( 'sanitize_text_field', $data );
	$data['sessionid'] = sanitize_text_field( $_GET['sessionid'] );

	echo ConcordPay::getInst()->check_response( $data );
	exit();
}

/**
 * Payment results message page.
 */
function concordpay_results() {
	// Callback url : 'http://yoursite.com/?concordpay_results=true'.
	if ( ! isset( $_GET['concordpay_results'] ) ||
		( 'true' !== sanitize_text_field( $_GET['concordpay_results'] ) && ! empty( $_GET['sessionid'] ) )
	) {
		return;
	}

    $session_id = sanitize_text_field( $_GET['sessionid'] );
	( new wpsc_merchant() )->go_to_transaction_results( $session_id );

	exit();
}

/**
 * Returns customer on checkout page.
 */
function return_concordpay_to_checkout() {
	global $wpdb;

	if ( ! isset( $_GET['concordpay_checkout'] ) ||
		'true' !== sanitize_text_field( $_GET['concordpay_checkout'] )
	) {
		return;
	}

	wp_safe_redirect( get_option( 'shopping_cart_url' ) );
	exit(); // follow the redirect with an exit, just to be sure.
}


/**
 * Update Payment Gateway settings.
 *
 * @return bool
 */
function submit_concordpay() {
	$concordpay_settings = array(
		'concordpay_merchant_id',
		'concordpay_secret_key',
		'concordpay_url',
		'concordpay_approve_url',
		'concordpay_decline_url',
		'concordpay_cancel_url',
		'concordpay_callback_url',
		'concordpay_locale',
	);

	foreach ( $concordpay_settings as $setting ) {
		if ( isset( $_POST[ $setting ] ) ) {
			update_option( $setting, sanitize_text_field( wp_unslash( $_POST[ $setting ] ) ) );
		}
	}

	if ( ConcordPay::getInst()->get_concordpay_locale() !== $concordpay_settings['concordpay_locale'] ) {
		ConcordPay::getInst()->set_concordpay_locale( $concordpay_settings['concordpay_locale'] );
	}

	return true;
}

/**
 * Generate admin page form.
 *
 * @return string
 */
function form_concordpay() {
	$cells             = get_concordpay_form_init();
	$concordpay_locale = ConcordPay::getInst()->get_concordpay_locale();
	$otp               = '';
	foreach ( $cells as $key => $val ) {
		$otp .= '<div><label>' . esc_attr( $val['name'] ) . '</label>' .
			( ( ! $val['isInput'] ) ?
				esc_attr( $val['code'] ) :
				'<input type="text" size="40" value="' . esc_attr( get_option( $key ) ) . '" name=' . esc_attr( $key ) . ' />'
			) . '<div class="subtext">' . ( ( '' === $val['subText'] ) ? '&nbsp;' : esc_attr( $val['subText'] ) ) . '</div>';

	}
	$otp .= '<label>' . esc_html__( 'Payment page language', 'concordpay-for-wp-ecommerce' ) . '</label>';
	$otp .= '<select name="concordpay_locale">';
	foreach ( ConcordPay::CONCORDPAY_ALLOWED_LOCALES as $locale ) {
		$otp .= '<option value="' . $locale . '" ' . ( $concordpay_locale === $locale ? 'selected' : '' ) . '>' . mb_strtoupper( $locale ) . '</option>';
	}
	$otp .= '</select><div class="subtext">' . esc_html__( 'The language of the ConcordPay payment page', 'concordpay-for-wp-ecommerce' ) . '</div></div>';

	$output = "<style>
		#concordpayoptions label { width:150px; font-weight:bold; display: inline-block; }
		#concordpayoptions .subtext { margin-left:160px; font-size:12px; font-style:italic; margin-bottom:12px;}
		#concordpayoptions { border:1px dotted #aeaeae; padding:5px; }
		</style>
		<div id='concordpayoptions'>$otp</div>";

	return $output;
}

/**
 * Init admin form fields.
 *
 * @return array[]
 */
function get_concordpay_form_init() {

	return array(
		'concordpay_merchant_id'  => array(
			'name'    => esc_html__( 'Merchant Account', 'concordpay-for-wp-ecommerce' ),
			'subText' => esc_html__( 'Given to Merchant by ConcordPay', 'concordpay-for-wp-ecommerce' ),
			'isInput' => true,
			'code'    => '',
		),
		'concordpay_secret_key'   => array(
			'name'    => esc_html__( 'Secret key', 'concordpay-for-wp-ecommerce' ),
			'subText' => esc_html__( 'Given to Merchant by ConcordPay', 'concordpay-for-wp-ecommerce' ),
			'isInput' => true,
			'code'    => '',
		),
		'concordpay_url'          => array(
			'name'    => esc_html__( 'System URL', 'concordpay-for-wp-ecommerce' ),
			'subText' => esc_html__( 'Default URL', 'concordpay-for-wp-ecommerce' ) . ' - https://pay.concord.ua/api/',
			'isInput' => true,
			'code'    => '',
		),
		'concordpay_approve_url'  => array(
			'name'    => esc_html__( 'Successful payment redirect URL', 'concordpay-for-wp-ecommerce' ),
			'subText' => esc_html__( 'Default URL', 'concordpay-for-wp-ecommerce' ) . ' - ' . get_site_url() . '/?concordpay_results=true',
			'isInput' => true,
			'code'    => '',
		),
		'concordpay_decline_url'  => array(
			'name'    => esc_html__( 'Redirect URL failed to pay', 'concordpay-for-wp-ecommerce' ),
			'subText' => esc_html__( 'Default URL', 'concordpay-for-wp-ecommerce' ) . ' - ' . get_site_url() . '/?concordpay_results=true',
			'isInput' => true,
			'code'    => '',
		),
		'concordpay_cancel_url'   => array(
			'name'    => esc_html__( 'Redirect URL in case of failure to make payment', 'concordpay-for-wp-ecommerce' ),
			'subText' => esc_html__( 'Default URL', 'concordpay-for-wp-ecommerce' ) . ' - ' . get_site_url() . '/?concordpay_results=true',
			'isInput' => true,
			'code'    => '',
		),
		'concordpay_callback_url' => array(
			'name'    => esc_html__( 'URL of the result information', 'concordpay-for-wp-ecommerce' ),
			'subText' => esc_html__( 'The URL to which will receive information about the result of the payment', 'concordpay-for-wp-ecommerce' ) . ' (' . get_site_url() . '/?concordpay_callback=true)',
			'isInput' => true,
			'code'    => '',
		),
	);
}

add_action( 'init', 'concordpay_callback' );
add_action( 'init', 'concordpay_results' );
add_action( 'init', 'return_concordpay_to_checkout' );

