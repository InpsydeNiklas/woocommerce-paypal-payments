<?php
/**
 * The Checkout helper.
 *
 * @package WooCommerce\PayPalCommerce\WcGateway\Helper;
 */

declare(strict_types=1);

namespace WooCommerce\PayPalCommerce\WcGateway\Helper;

use DateTime;
use WC_Order;
use WC_Order_Item_Product;
use WC_Product;
use WC_Product_Variable;
use WC_Product_Variation;

/**
 * CheckoutHelper class.
 */
class CheckoutHelper {

	/**
	 * Checks if amount is allowed within the given range.
	 *
	 * @param float $minimum Minimum amount.
	 * @param float $maximum Maximum amount.
	 * @return bool
	 */
	public function is_checkout_amount_allowed( float $minimum, float $maximum ): bool {
		$cart = WC()->cart ?? null;
		if ( $cart && ! is_checkout_pay_page() ) {
			$cart_total = (float) $cart->get_total( 'numeric' );
			if ( $cart_total < $minimum || $cart_total > $maximum ) {
				return false;
			}

			$items = $cart->get_cart_contents();
			foreach ( $items as $item ) {
				$product = wc_get_product( $item['product_id'] );
				if ( is_a( $product, WC_Product::class ) && ! $this->is_physical_product( $product ) ) {
					return false;
				}
			}
		}

		if ( is_wc_endpoint_url( 'order-pay' ) ) {
			/**
			 * Needed for WordPress `query_vars`.
			 *
			 * @psalm-suppress InvalidGlobal
			 */
			global $wp;

			if ( isset( $wp->query_vars['order-pay'] ) && absint( $wp->query_vars['order-pay'] ) > 0 ) {
				$order_id = absint( $wp->query_vars['order-pay'] );
				$order    = wc_get_order( $order_id );
				if ( is_a( $order, WC_Order::class ) ) {
					$order_total = (float) $order->get_total();
					if ( $order_total < $minimum || $order_total > $maximum ) {
						return false;
					}

					foreach ( $order->get_items() as $item_id => $item ) {
						if ( is_a( $item, WC_Order_Item_Product::class ) ) {
							$product = wc_get_product( $item->get_product_id() );
							if ( is_a( $product, WC_Product::class ) && ! $this->is_physical_product( $product ) ) {
								return false;
							}
						}
					}
				}
			}
		}

		return true;
	}

	/**
	 * Ensures date is valid and at least 18 years back.
	 *
	 * @param string $date The date.
	 * @param string $format The date format.
	 * @return bool
	 */
	public function validate_birth_date( string $date, string $format = 'Y-m-d' ): bool {
		$d = DateTime::createFromFormat( $format, $date );
		if ( false === $d ) {
			return false;
		}

		if ( $date !== $d->format( $format ) ) {
			return false;
		}

		$date_time = strtotime( $date );
		if ( $date_time && time() < strtotime( '+18 years', $date_time ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Ensures product is ready for PUI.
	 *
	 * @param WC_Product $product WC product.
	 * @return bool
	 */
	public function is_physical_product( WC_Product $product ):bool {
		if ( $product->is_downloadable() || $product->is_virtual() ) {
			return false;
		}

		if ( is_a( $product, WC_Product_Variable::class ) ) {
			foreach ( $product->get_available_variations( 'object' ) as $variation ) {
				if ( is_a( $variation, WC_Product_Variation::class ) ) {
					if ( true === $variation->is_downloadable() || true === $variation->is_virtual() ) {
						return false;
					}
				}
			}
		}

		return true;
	}
}
