<?php namespace Lean\Woocommerce\Api;

use Lean\AbstractEndpoint;
use Epoch2\HttpCodes;
use Lean\Woocommerce\Utils\ErrorCodes;
use Lean\Woocommerce\Utils\Hooks;

/**
 * Class Order. This class implements the order endpoints. Users are able
 * to create a new order with the current session cart or retreive their
 * orders (this is only possible for authenticated users!).
 *
 * @package Lean\Woocommerce\Api
 */
class Order extends AbstractEndpoint
{
	const BILLING_KEY = 'billing';
	const SHIPPING_KEY = 'shipping';

	/**
	 * Array of Shipping required filds.
	 *
	 * @var array
	 */
	static private $shipping_required_fields = [];

	/**
	 * Array of Billing required fields.
	 *
	 * @var array
	 */
	static private $billing_required_fields = [];

	const ORDERS_PER_PAGE = 10;
	/**
	 * Endpoint path
	 *
	 * @Override
	 * @var String
	 */
	protected $endpoint = '/ecommerce/order';

	/**
	 * Endpoint callback override.
	 *
	 * @param \WP_REST_Request $request Request object.
	 *
	 * @return array|\WP_Error
	 */
	public function endpoint_callback( \WP_REST_Request $request ) {
		$method = $request->get_method();

		if ( \WP_REST_Server::CREATABLE === $method ) {
			// Create an order.
			return self::place_order( $request );
		} else if ( \WP_REST_Server::READABLE === $method ) {
			// Get all Logged User orders. Returns nothing if the user is not logged in.
			return self::get_user_orders();
		} else {
			return new \WP_Error(
				ErrorCodes::METHOD_ERROR,
				'Method not allowed',
				[ 'status' => HttpCodes::HTTP_METHOD_NOT_ALLOWED ]
			);
		}
	}

	/**
	 * Set the options of the endpoint. Allow http methods.
	 *
	 * @return array
	 */
	protected function endpoint_options() {
		return [
			'methods' => [ \WP_REST_Server::CREATABLE , \WP_REST_Server::READABLE ],
			'callback' => [ $this, 'endpoint_callback' ],
			'args' => $this->endpoint_args(),
		];
	}

	/**
	 * Set the args for this endpoint.
	 *
	 * @return array
	 */
	public function endpoint_args() {
		return [
			'product_id' => [
				'default' => false,
				'required' => false,
				'validate_callback' => function( $order_id ) {
					return false === $order_id || intval( $order_id ) >= 0;
				},
			],
		];
	}


	/**
	 * Create the order based on the current cart, also it returns the order.
	 * Client cart can't be empty to create the order.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return array Order.
	 */
	public static function place_order( \WP_REST_Request $request ) {

		$cart = Cart::get_cart();


		if ( $cart->is_empty() ) {
			return new \WP_Error(
				ErrorCodes::BAD_REQUEST,
				'Your cart is empty. Order was not created.',
				[ 'status' => HttpCodes::HTTP_BAD_REQUEST ]
			);
		}
		
		$cart->calculate_totals();
		do_action( Hooks::PRE_ORDER, $request, $cart );

		// Load our cart.
		\WC()->cart = $cart;


		$checkout = new \WC_Checkout();

		// Get the Billing and Shipping required fields.
		self::$billing_required_fields = array_keys( $checkout->checkout_fields[ self::BILLING_KEY ] );
		self::$shipping_required_fields = array_keys( $checkout->checkout_fields[ self::SHIPPING_KEY ] );

		$order_id = $checkout->create_order();
		$order = new \WC_Order( $order_id );

		do_action( Hooks::AFTER_ORDER, $request, $cart );

		// If the user is not logged in, we need to pass the billing and shipping address to the order.
		if ( ! is_user_logged_in() ) {
			$order = self::update_guest_order( $request, $order );
			if ( is_wp_error( $order ) ) {
				// Because we can have errors, delete the order and return the error.
				wp_delete_post( $order_id );
				return $order;
			}
		}

		$order->get_total();
		// Empty the current cart.
		$cart->empty_cart();

		return $order;
	}

	/**
	 * If the user is not logged in, we need the shipping and billing info in order to create the shop order.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @param \WC_Order 	   $order Order.
	 * @return \WP_Error || \WC_Order
	 */
	public static function update_guest_order( \WP_REST_Request $request, $order ) {
		do_action( Hooks::GUEST_PRE_UPDATE_ORDER, $request, $order );

		$params = $request->get_body_params();

		if ( self::check_post_address( $params ) ) {
			return new \WP_Error(
				ErrorCodes::BAD_REQUEST,
				'Guest order needs billing and shipping information in POST body.',
				[ 'status' => HttpCodes::HTTP_BAD_REQUEST ]
			);
		}

		if ( self::fields_have_errors( $params ) ) {
			return new \WP_Error(
				ErrorCodes::BAD_REQUEST,
				'Shipping and/or billing address are not completed. Please provide the minimum fields in POST body.',
				[ 'status' => HttpCodes::HTTP_BAD_REQUEST ]
			);
		}

		// Update order with shipping and billing information for the Guest.
		$order->set_address( $params['shipping'], self::SHIPPING_KEY );
		$order->set_address( $params['billing'], self::BILLING_KEY );

		do_action( Hooks::GUEST_AFTER_UPDATE_ORDER, $request, $order );

		return $order;
	}

	/**
	 * Check if the required parameters are on the POST body.
	 *
	 * @param array	$params  Post body parameters.
	 * @return bool
	 */
	public static function check_post_address( $params ) {
		return ! isset( $params[ self::SHIPPING_KEY ] ) || ! isset( $params[ self::BILLING_KEY ] );
	}

	/**
	 * Check if we are receiving the minimum required fields.
	 *
	 * @param array	$params  Post body parameters.
	 * @return int Number of errors.
	 */
	public static function fields_have_errors( $params ) {
		$errors = 0;

		$required_billing = count( self::$billing_required_fields );
		$required_shipping = count( self::$shipping_required_fields );

		$array_billing = array_flip( self::$billing_required_fields );
		$array_shipping = array_flip( self::$shipping_required_fields );

		if ( count( array_intersect_key( $params[ self::BILLING_KEY ], $array_billing ) ) < $required_billing ) {
			$errors += 1;
		}

		if ( count( array_intersect_key( $params[ self::SHIPPING_KEY ], $array_shipping ) ) < $required_shipping ) {
			$errors += 1;
		}

		return $errors;
	}

	/**
	 * Return all logged user orders. If the user is not logged_in returns an empty array.
	 *
	 * @return array Orders|Empty array.
	 */
	public static function get_user_orders() {
		if ( is_user_logged_in() ) {
			$customer_orders = get_posts( array(
				'posts_per_page'	=> self::ORDERS_PER_PAGE,
				'meta_key'    		=> '_customer_user',
				'meta_value'  		=> get_current_user_id(),
				'post_type'   		=> wc_get_order_types(),
				'post_status' 		=> array_keys( wc_get_order_statuses() ),
			) );

			return $customer_orders;
		}

		return [];
	}
}
