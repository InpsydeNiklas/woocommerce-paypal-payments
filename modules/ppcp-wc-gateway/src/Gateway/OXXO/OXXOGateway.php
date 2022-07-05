<?php
/**
 * The OXXO Gateway
 *
 * @package WooCommerce\PayPalCommerce\WcGateway\Gateway
 */

declare(strict_types=1);

namespace WooCommerce\PayPalCommerce\WcGateway\Gateway\OXXO;

use Psr\Log\LoggerInterface;
use WC_Payment_Gateway;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\OrderEndpoint;
use WooCommerce\PayPalCommerce\ApiClient\Exception\PayPalApiException;
use WooCommerce\PayPalCommerce\ApiClient\Exception\RuntimeException;
use WooCommerce\PayPalCommerce\ApiClient\Factory\PurchaseUnitFactory;

/**
 * Class PayUponInvoiceGateway.
 */
class OXXOGateway extends WC_Payment_Gateway {
	const ID = 'ppcp-oxxo-gateway';

	/**
	 * The order endpoint.
	 *
	 * @var OrderEndpoint
	 */
	protected $order_endpoint;

	/**
	 * The purchase unit factory.
	 *
	 * @var PurchaseUnitFactory
	 */
	protected $purchase_unit_factory;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface
	 */
	protected $logger;

	/**
	 * OXXOGateway constructor.
	 *
	 * @param OrderEndpoint       $order_endpoint The order endpoint.
	 * @param PurchaseUnitFactory $purchase_unit_factory The purchase unit factory.
	 * @param LoggerInterface     $logger The logger.
	 */
	public function __construct(
		OrderEndpoint $order_endpoint,
		PurchaseUnitFactory $purchase_unit_factory,
		LoggerInterface $logger
	) {
		 $this->id = self::ID;

		$this->method_title       = __( 'OXXO', 'woocommerce-paypal-payments' );
		$this->method_description = __( 'OXXO is a Mexican chain of convenience stores.', 'woocommerce-paypal-payments' );

		$gateway_settings  = get_option( 'woocommerce_ppcp-oxxo-gateway_settings' );
		$this->title       = $gateway_settings['title'] ?? $this->method_title;
		$this->description = $gateway_settings['description'] ?? __( 'OXXO allows you to pay bills and online purchases in-store with cash.', 'woocommerce-paypal-payments' );

		$this->init_form_fields();
		$this->init_settings();

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array(
				$this,
				'process_admin_options',
			)
		);

		$this->order_endpoint        = $order_endpoint;
		$this->purchase_unit_factory = $purchase_unit_factory;
		$this->logger                = $logger;
	}

	/**
	 * Initialize the form fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'       => __( 'Enable/Disable', 'woocommerce-paypal-payments' ),
				'type'        => 'checkbox',
				'label'       => __( 'OXXO', 'woocommerce-paypal-payments' ),
				'default'     => 'no',
				'desc_tip'    => true,
				'description' => __( 'Enable/Disable OXXO payment gateway.', 'woocommerce-paypal-payments' ),
			),
			'title'       => array(
				'title'       => __( 'Title', 'woocommerce-paypal-payments' ),
				'type'        => 'text',
				'default'     => $this->title,
				'desc_tip'    => true,
				'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce-paypal-payments' ),
			),
			'description' => array(
				'title'       => __( 'Description', 'woocommerce-paypal-payments' ),
				'type'        => 'text',
				'default'     => $this->description,
				'desc_tip'    => true,
				'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-paypal-payments' ),
			),
		);
	}

	/**
	 * Processes the order.
	 *
	 * @param int $order_id The WC order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$wc_order = wc_get_order( $order_id );
		$wc_order->update_status( 'on-hold', __( 'Awaiting OXXO payment.', 'woocommerce-paypal-payments' ) );

		$purchase_unit = $this->purchase_unit_factory->from_wc_order( $wc_order );

		try {
			$order          = $this->order_endpoint->create( array( $purchase_unit ) );
			$payment_source = array(
				'oxxo' => array(
					'name'         => $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name(),
					'email'        => $wc_order->get_billing_email(),
					'country_code' => $wc_order->get_billing_country(),
				),
			);
			$payment_method = $this->order_endpoint->confirm_payment_source( $order->id(), $payment_source );

			foreach ( $payment_method->links as $link ) {
				if ( $link->rel === 'payer-action' ) {
					$wc_order->add_meta_data( 'ppcp_oxxo_payer_action', $link->href );
					$wc_order->save_meta_data();
				}
			}
		} catch ( RuntimeException $exception ) {
			$error = $exception->getMessage();

			if ( is_a( $exception, PayPalApiException::class ) && is_array( $exception->details() ) ) {
				$details = '';
				foreach ( $exception->details() as $detail ) {
					$issue       = $detail->issue ?? '';
					$field       = $detail->field ?? '';
					$description = $detail->description ?? '';
					$details    .= $issue . ' ' . $field . ' ' . $description . '<br>';
				}

				$error = $details;
			}

			$this->logger->error( $error );
			wc_add_notice( $error, 'error' );

			$wc_order->update_status(
				'failed',
				$error
			);

			return array(
				'result'   => 'failure',
				'redirect' => wc_get_checkout_url(),
			);
		}

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $wc_order ),
		);
	}
}
