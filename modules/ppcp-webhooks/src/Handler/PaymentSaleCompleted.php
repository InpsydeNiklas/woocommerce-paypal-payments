<?php
/**
 * Handles the WebhookPAYMENT.SALE.COMPLETED
 *
 * @package WooCommerce\PayPalCommerce\Webhooks\Handler
 */

declare(strict_types=1);

namespace WooCommerce\PayPalCommerce\Webhooks\Handler;

use Psr\Log\LoggerInterface;
use WC_Order;
use WooCommerce\PayPalCommerce\WcGateway\Processor\TransactionIdHandlingTrait;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Class PaymentSaleCompleted
 */
class PaymentSaleCompleted implements RequestHandler {

	use TransactionIdHandlingTrait;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	private $logger;

	/**
	 * PaymentSaleCompleted constructor.
	 *
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * The event types a handler handles.
	 *
	 * @return string[]
	 */
	public function event_types(): array {
		return array( 'PAYMENT.SALE.COMPLETED' );
	}

	/**
	 * Whether a handler is responsible for a given request or not.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return bool
	 */
	public function responsible_for_request( WP_REST_Request $request ): bool {
		return in_array( $request['event_type'], $this->event_types(), true );

	}

	/**
	 * Responsible for handling the request.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_request( WP_REST_Request $request ): WP_REST_Response {
		$response = array( 'success' => false );
		if ( is_null( $request['resource'] ) ) {
			return new WP_REST_Response( $response );
		}

		$billing_agreement_id = wc_clean( wp_unslash( $request['resource']['billing_agreement_id'] ?? '' ) );
		if ( ! $billing_agreement_id ) {
			$message = 'Could not retrieve billing agreement id for subscription.';
			$this->logger->warning( $message, array( 'request' => $request ) );
			$response['message'] = $message;
			return new WP_REST_Response( $response );
		}

		$args          = array(
			'meta_query' => array(
				array(
					'key'     => 'ppcp_subscription',
					'value'   => $billing_agreement_id,
					'compare' => '=',
				),
			),
		);
		$subscriptions = wcs_get_subscriptions( $args );

		if ( ! $subscriptions ) {
			$billing_agreement_id = is_string( $billing_agreement_id ) ? $billing_agreement_id : '';
			$message              = "Could not retrieve WC subscriptions for billing agreement: {$billing_agreement_id}";
			$this->logger->warning( $message, array( 'request' => $request ) );
			$response['message'] = $message;
			return new WP_REST_Response( $response );
		}

		foreach ( $subscriptions as $subscription ) {
			$renewal_order = wcs_create_renewal_order( $subscription );
			if ( is_a( $renewal_order, WC_Order::class ) ) {
				$renewal_order->payment_complete();

				$transaction_id = wc_clean( wp_unslash( $request['resource']['id'] ?? '' ) );
				if ( $transaction_id && is_string( $transaction_id ) ) {
					$this->update_transaction_id( $transaction_id, $renewal_order );
				}
			}
		}

		$response['success'] = true;
		return new WP_REST_Response( $response );
	}
}
