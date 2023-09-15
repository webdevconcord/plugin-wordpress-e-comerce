<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ConcordPay
 */
class ConcordPay extends wpsc_merchant {

	/**
	 * ConcordPay API URL
	 *
	 * @var string
	 */
	protected $url;

	/**
	 * ConcordPay instance existence flag.
	 *
	 * @var bool
	 */
	protected static $inst = false;

	/**
	 * ConcordPay merchant ID.
	 *
	 * @var string
	 */
	protected $merchant_id;

	/**
	 * ConcordPay merchant secret key.
	 *
	 * @var string
	 */
	protected $secret_key;

	/**
	 * ConcordPay payment page locale.
	 *
	 * @var string
	 */
	protected $concordpay_locale;

	const ORDER_APPROVED             = 'Approved';
	const ORDER_DECLINED             = 'Declined';
	const RESPONSE_TYPE_REVERSE      = 'reverse';
	const CONCORDPAY_ALLOWED_LOCALES = array( 'en', 'ru', 'ua' );
	const CURRENCY_HRYVNA            = 'UAH';

	/**
	 * Array keys for generate response signature.
	 *
	 * @var string[]
	 */
	protected $keys_for_response_signature = array(
		'merchantAccount',
		'orderReference',
		'amount',
		'currency',
	);

	/**
	 * Array keys for generate request signature.
	 *
	 * @var string[]
	 */
	protected $keys_for_signature = array(
		'merchant_id',
		'order_id',
		'amount',
		'currency_iso',
		'description',
	);

	/**
	 * Payment form code.
	 *
	 * @var string
	 */
	protected $answer = '';

	/**
	 * ConcordPay constructor.
	 */
	private function __construct() {
		parent::__construct();
		$this->url               = ( ! empty( get_option( 'concordpay_url' ) ) ) ?
			esc_url( get_option( 'concordpay_url' ) ) :
			'https://pay.concord.ua/api/';
		$this->merchant_id       = esc_attr( get_option( 'concordpay_merchant_id' ) );
		$this->secret_key        = esc_attr( get_option( 'concordpay_secret_key' ) );
		$this->concordpay_locale = empty( get_option( 'concordpay_locale' ) ) ? 'en' : esc_attr( get_option( 'concordpay_locale' ) );
	}

	/**
	 * Disable cloning of objects
	 *
	 * @ignore
	 */
	private function __clone() {
	}

	/**
	 * Returns string representation of payment form.
	 *
	 * @return string
	 */
	public function __toString() {
		return ( '' === $this->answer ) ? '<!-- Answer are not exists -->' : $this->answer;
	}

	/**
	 * Returns instance of ConcordPay.
	 *
	 * @return bool|concordpay
	 */
	public static function getInst() {
		if ( false === self::$inst ) {
			self::$inst = new ConcordPay();
		}

		return self::$inst;
	}

	/**
	 * Generate request signature.
	 *
	 * @param array $options Request data.
	 * @return string
	 */
	public function get_request_signature( $options ) {
		return $this->get_signature( $options, $this->keys_for_signature );
	}

	/**
	 * Generate response signature.
	 *
	 * @param array $options Response data.
	 * @return string
	 */
	public function get_response_signature( $options ) {
		return $this->get_signature( $options, $this->keys_for_response_signature );
	}

	/**
	 * Generate signature for operation.
	 *
	 * @param array $option Request or response data.
	 * @param array $keys Keys for signature.
	 * @return string
	 */
	public function get_signature( $option, $keys ) {
		$hash = array();
		foreach ( $keys as $data_key ) {
			if ( ! isset( $option[ $data_key ] ) ) {
				continue;
			}
			if ( is_array( $option[ $data_key ] ) ) {
				foreach ( $option[ $data_key ] as $v ) {
					$hash[] = $v;
				}
			} else {
				$hash [] = $option[ $data_key ];
			}
		}
		$hash = implode( ';', $hash );

		return hash_hmac( 'md5', $hash, $this->secret_key );
	}

	/**
	 * Fills the payment form with data.
	 *
	 * @param array $data Order data.
	 * @return $this
	 */
	public function fill_pay_form( $data ) {
		$data['merchant_id'] = $this->merchant_id;
		$data['signature']   = $this->get_request_signature( $data );
		$this->answer        = $this->generate_form( $data );

		return $this;
	}

	/**
	 * Generate payment form with fields
	 *
	 * @param array $data Order data.
	 * @return string
	 */
	protected function generate_form( $data ) {
		$form = '<form method="post" id="form_concordpay" action="' . $this->url . '" accept-charset="utf-8">';
		foreach ( $data as $k => $v ) {
			$form .= $this->print_input( $k, $v );
		}

		return $form . '<input type="submit" style="display:none;" /></form>';
	}

	/**
	 * Returns payment form code.
	 *
	 * @return string
	 */
	public function get_answer() {
		return $this->answer;
	}

	/**
	 * Print hidden input in form.
	 *
	 * @param string       $name Field name.
	 * @param string|array $val Field value.
	 * @return string
	 */
	protected function print_input( $name, $val ) {
		$str = '';
		if ( ! is_array( $val ) ) {
			return '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $val ) . '">' . "\n<br />";
		}

		foreach ( $val as $v ) {
			$str .= $this->print_input( $name . '[]', $v );
		}

		return $str;
	}

	/**
	 * Validate the response from the Payment Gateway and changes the order status.
	 *
	 * @param array $response Payment Gateway response data.
	 * @return string|void
	 */
	public function check_response( $response ) {
		global $wpdb;
		$session_id = $response['sessionid'];

		$ref = get_bloginfo() . '_' . md5( 'concordpay_' . $session_id );

		$purchase_log = new WPSC_Purchase_Log( $session_id, 'sessionid' );
		if ( ! $purchase_log->exists() || $purchase_log->is_transaction_completed() ) {
			return esc_html__( 'An error has occurred during payment. Please contact us to ensure your order has submitted.', 'concordpay-for-wp-ecommerce' );
		}

		if ( sanitize_text_field( get_option( 'concordpay_merchant_id' ) ) !== $response['merchantAccount'] ) {
			return esc_html__( 'An error has occurred during payment. Merchant data is incorrect.', 'concordpay-for-woocommerce' );
		}

		$response_signature = $this->get_response_signature( $response );

		if ( empty( $response['merchantSignature'] ) || $response['merchantSignature'] !== $response_signature ) {
			die( esc_html__( 'An error has occurred during payment. Signature is not valid.', 'concordpay-for-wp-ecommerce' ) );
		}

		$transaction_status = $response['transactionStatus'];

		$order_status = WPSC_Purchase_Log::ORDER_RECEIVED;
		// Declined payment.
		if ( self::ORDER_DECLINED === $transaction_status ) {
			$order_status = WPSC_Purchase_Log::PAYMENT_DECLINED;
		}

		if ( self::ORDER_APPROVED === $transaction_status ) {
			// Refunded payment.
			if ( isset( $response['type'] ) && self::RESPONSE_TYPE_REVERSE === sanitize_text_field( $response['type'] ) ) {
				$order_status = WPSC_Purchase_Log::REFUNDED;
			} else {
				// Success payment.
				$order_status = WPSC_Purchase_Log::ACCEPTED_PAYMENT;
			}
		}

		if ( WPSC_Purchase_Log::ORDER_RECEIVED !== $order_status ) {
			$purchase_log->set( 'processed', $order_status );
			$purchase_log->save();
			transaction_results( $session_id, false, $ref );
		}

		return null;
	}

	/**
	 * Return Concordpay payment page locale.
	 *
	 * @return string
	 */
	public function get_concordpay_locale() {
		return $this->concordpay_locale;
	}

	/**
	 * Set Concordpay payment page locale.
	 *
	 * @return void
	 * @param string $locale Language code.
	 */
	public function set_concordpay_locale( $locale ) {
		if ( in_array( $locale, self::CONCORDPAY_ALLOWED_LOCALES, true ) ) {
			$this->concordpay_locale = $locale;
		}
	}
}


