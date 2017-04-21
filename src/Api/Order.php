<?php namespace Lean\Woocommerce\Api;

use Lean\AbstractEndpoint;
use Epoch2\HttpCodes;
use Lean\Woocommerce\Utils\ErrorCodes;
use Lean\Woocommerce\Utils\Hooks;
use Lean\Woocommerce\Controllers\UserController;

/**
 * Class Order. This class implements the order endpoints. Users are able
 * to create a new order with the current session cart or retreive their
 * orders (this is only possible for authenticated users!).
 *
 * @package Lean\Woocommerce\Api
 */
class Order extends AbstractEndpoint {
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
			// Get all User by email or token. Returns empty array if the user is not found.
			return self::format_orders( self::get_user_orders( $request ) );
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
			'token_id' => [
				'default' => false,
				'required' => false,
				'validate_callback' => function( $token_id ) {
					return false === $token_id || is_string( $token_id );
				},
			],
			'user_email' => [
				'default' => '',
				'required' => false,
				'validate_callback' => function( $user_email ) {
					 return empty( $user_email ) || filter_var( $user_email, FILTER_VALIDATE_EMAIL );
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
		$token_id = $request->get_param( 'token_id' ) ? $request->get_param( 'token_id' ) : false;
		$user_email = trim( $request->get_param( 'user_email' ) );
		$cart = Cart::get_cart( $token_id );

		if ( $cart->is_empty() ) {
			return new \WP_Error(
				ErrorCodes::BAD_REQUEST,
				'Your cart is empty. Order was not created.',
				[ 'status' => HttpCodes::HTTP_BAD_REQUEST ]
			);
		}

		$cart->calculate_totals();
		do_action( Hooks::PRE_ORDER, $request, $cart );

		$checkout = new \WC_Checkout();

		// Get the Billing and Shipping required fields.
		self::$billing_required_fields = array_keys( $checkout->checkout_fields[ self::BILLING_KEY ] );
		self::$shipping_required_fields = array_keys( $checkout->checkout_fields[ self::SHIPPING_KEY ] );

		$order_id = $checkout->create_order();

		if ( $token_id || $user_email ) {
			if ( $token_id ) {
				$user = UserController::get_user_by_token( $token_id );
			} else {
				$user = get_user_by( 'email', $user_email );
			}
			wc_create_order([
				'order_id' => $order_id,
				// $user is not always going to be an object it can be zero.
				'customer_id' => 0 === $user ? $user : $user->ID,
			]);
		}

		$order = new \WC_Order( $order_id );

		$order = self::update_guest_order( $request, $order );

		if ( is_wp_error( $order ) ) {
			// Because we can have errors in $order, delete the order and return the error.
			if ( in_array( get_post_type( $order_id ), wc_get_order_types(), true ) ) {
				wp_delete_post( $order_id );
			}

			return $order;
		}

		$order->get_total();

		do_action( Hooks::AFTER_ORDER, $request, $order, $cart );

		// Empty the current cart.
		$cart->empty_cart();

		if ( $token_id ) {
			Cart::empty_cart( $token_id );
		}

		return $order;
	}

	/**
	 * If the user is not logged in, we need the shipping and billing info
	 * in order to create the shop order.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @param \WC_Order 	   $order Order.
	 * @return \WP_Error || \WC_Order
	 */
	public static function update_guest_order( \WP_REST_Request $request, $order ) {
		do_action( Hooks::GUEST_PRE_UPDATE_ORDER, $request, $order );

		$params = $request->get_body();

		$params = json_decode( $params, true );

		if ( self::validate_address( $params ) ) {
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
		$order->set_address( $params[ self::SHIPPING_KEY ], self::SHIPPING_KEY );
		$order->set_address( $params[ self::BILLING_KEY ], self::BILLING_KEY );

		do_action( Hooks::GUEST_AFTER_UPDATE_ORDER, $request, $order );

		return $order;
	}

	/**
	 * Check if the required parameters are on the POST body.
	 *
	 * @param array	$params  Post body parameters.
	 * @return bool
	 */
	public static function validate_address( $params ) {
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

		$errors += self::count_errors( $params[ self::BILLING_KEY ], self::$billing_required_fields, self::BILLING_KEY );
		$errors += self::count_errors( $params[ self::SHIPPING_KEY ], self::$shipping_required_fields, self::SHIPPING_KEY );

		return $errors;
	}

	/**
	 * Helper function to count the number of different fields between two
	 * arrays.
	 *
	 * @param array  $keys Array with the keys from POST parameters.
	 * @param array  $required Array with the required keys.
	 * @param string $used_key Shipping/Billing key in each case.
	 * @return int Number of errors.
	 */
	protected static function count_errors( $keys, $required, $used_key ) {
		$flip = array_flip( $required );
		$tmp = [];

		foreach ( $keys as $key => $value ) {
			$tmp[ $used_key . '_' . $key ] = $value;
		}

		return (int) ( count( array_intersect_key( $tmp, $flip ) ) < count( $required ) );
	}

	/**
	 * Return all user orders by email or token ID. If the user email or token
	 * is not specified returns an empty array.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return array Orders|Empty array.
	 */
	public static function get_user_orders( $request ) {
		$token_id = $request->get_param( 'token_id' );
		$user_email = trim( $request->get_param( 'user_email' ) );
		$customer_orders = [];

		if ( ! empty( $user_email ) || $token_id ) {
			if ( empty( $user_email ) ) {
				$user = UserController::get_user_by_token( $token_id );
			} else {
				$user = get_user_by( 'email', $user_email );
				$user = false === $user ? 0 : $user;
			}

			// Return early since the $user is not found.
			if ( 0 === $user ) {
				return [];
			}

			$user_id = $user->ID;
			$order_statuses = array_keys( wc_get_order_statuses() );

			$customer_orders = get_posts( array(
				'posts_per_page'	=> self::ORDERS_PER_PAGE,
				'meta_key'    		=> '_customer_user',
				'meta_value'  		=> $user_id,
				'post_type'   		=> wc_get_order_types(),
				'post_status' 		=> $order_statuses,
			) );
		}
		return $customer_orders;
	}

	/**
	 * Format orders after getting them.
	 *
	 * @param array $orders The orders.
	 * @return array Orders formatted.
	 */
	public static function format_orders( $orders ) {
		$response = [];

		foreach ( $orders as $order ) {
			$new_order = new \WC_Order( $order->ID );
			$new_order->calculate_totals();
			$new_order->total = $new_order->get_total();

			// Add custom values to the order if needed.
			$new_order = apply_filters( Hooks::FORMAT_ORDER_FILTER, $new_order );

			$response[] = $new_order;
		}

		return $response;
	}
}
