<?php namespace Lean\Woocommerce\Modules\Cart;

use Lean\AbstractEndpoint;

/**
 * Class CartEndpoint.
 *
 * @package Leean\Woocomerce\Modules\Cart
 */
class CartEndpoint extends AbstractEndpoint
{
	/**
	 * Endpoint path
	 *
	 * @Override
	 * @var String
	 */
	protected $endpoint = '/ecommerce/cart';

	const POST_METHOD = 'POST';
	const UPDATE_METHOD = 'UPDATE';

	/**
	 * @param \WP_REST_Request $request
	 *
	 * @return array|\WP_Error
	 */
	public function endpoint_callback( \WP_REST_Request $request ) {
		$method = $request->get_method();

		if ( in_array($method, [\WP_REST_Server::READABLE]) ) {
			return self::get_cart();
		} else if ( in_array( $method, str_getcsv( \WP_REST_Server::EDITABLE ) ) ) {
			return self::add_to_cart( $request );
		} else {
			return new \WP_Error( 405, 'Method not allowed', [ 'status' => 405 ] );
		}
	}

	/**
	 * Set the options of the endpoint. Allow http methods.
	 *
	 * @return array
	 */
	protected function endpoint_options() {
		return [
			'methods' => array_merge(
				[ \WP_REST_Server::READABLE ],
				str_getcsv( \WP_REST_Server::EDITABLE )
			),
			'callback' => [ $this, 'endpoint_callback' ],
			'args' => $this->endpoint_args(),
		];
	}

	/**
	 * Set the args for this endpoint.
	 *
	 * @return array
	 */
	public function endpoint_args() 	{
		return [
			'product_id' => [
				'default' => false,
				'required' => false,
				'validate_callback' => function ( $product_id ) {
					return false === $product_id || intval ( $product_id ) >= 0;
				}
			]
		];
	}

	/**
	 * Add an item to the cart, and return the new cart.
	 *
	 * @param \WP_REST_Request $request
	 * @return array
	 */
	public static function add_to_cart( \WP_REST_Request $request ) {

		$product_id = $request->get_param('product_id');

		if ( !$product_id ) {
			return new \WP_Error( 400, 'Invalid data', [ 'status' => 400 ] );
		}

		$cart = self::get_cart();
		$cart->add_to_cart( intval( $product_id ) );

		return $cart;
	}

	/**
	 * Get the cart for the current session user.
	 *
	 * @return array
	 */
	public static function get_cart() {
		\WC()->cart->get_cart_from_session();

		return \WC()->cart;
	}

}