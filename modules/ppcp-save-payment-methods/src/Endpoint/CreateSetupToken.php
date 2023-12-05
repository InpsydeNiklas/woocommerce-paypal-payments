<?php
/**
 * The Create Setup Token endpoint.
 *
 * @package WooCommerce\PayPalCommerce\ApiClient\Endpoint
 */

declare(strict_types=1);

namespace WooCommerce\PayPalCommerce\SavePaymentMethods\Endpoint;

use Exception;
use WooCommerce\PayPalCommerce\ApiClient\Endpoint\PaymentMethodTokensEndpoint;
use WooCommerce\PayPalCommerce\ApiClient\Entity\PaymentSource;
use WooCommerce\PayPalCommerce\Button\Endpoint\EndpointInterface;
use WooCommerce\PayPalCommerce\Button\Endpoint\RequestData;

/**
 * Class CreateSetupToken
 */
class CreateSetupToken implements EndpointInterface {

	const ENDPOINT = 'ppc-create-setup-token';

	/**
	 * The request data helper.
	 *
	 * @var RequestData
	 */
	private $request_data;

	/**
	 * Payment Method Tokens endpoint.
	 *
	 * @var PaymentMethodTokensEndpoint
	 */
	private $payment_method_tokens_endpoint;

	/**
	 * CreateSetupToken constructor.
	 *
	 * @param RequestData                 $request_data The request data helper.
	 * @param PaymentMethodTokensEndpoint $payment_method_tokens_endpoint Payment Method Tokens endpoint.
	 */
	public function __construct(
		RequestData $request_data,
		PaymentMethodTokensEndpoint $payment_method_tokens_endpoint
	) {
		$this->request_data                   = $request_data;
		$this->payment_method_tokens_endpoint = $payment_method_tokens_endpoint;
	}

	/**
	 * Returns the nonce.
	 *
	 * @return string
	 */
	public static function nonce(): string {
		return self::ENDPOINT;
	}

	/**
	 * Handles the request.
	 *
	 * @return bool
	 * @throws Exception On Error.
	 */
	public function handle_request(): bool {
		try {
			$this->request_data->read_request( $this->nonce() );

			/**
			 * Suppress ArgumentTypeCoercion
			 *
			 * @psalm-suppress ArgumentTypeCoercion
			 */
			$payment_source = new PaymentSource(
				'paypal',
				(object) array(
					'usage_type'         => 'MERCHANT',
					'experience_context' => (object) array(
						'return_url' => esc_url( wc_get_account_endpoint_url( 'payment-methods' ) ),
						'cancel_url' => esc_url( wc_get_account_endpoint_url( 'add-payment-method' ) ),
					),
				)
			);

			$result = $this->payment_method_tokens_endpoint->setup_tokens( $payment_source );

			wp_send_json_success( $result );
			return true;
		} catch ( Exception $exception ) {
			wp_send_json_error();
			return false;
		}
	}
}
