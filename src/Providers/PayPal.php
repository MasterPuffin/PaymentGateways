<?php

namespace MasterPuffin\PaymentGateways\Providers;

use MasterPuffin\PaymentGateways\Exceptions\GatewayException;
use MasterPuffin\PaymentGateways\Exceptions\NotImplementedException;
use MasterPuffin\PaymentGateways\Payment;
use MasterPuffin\PaymentGateways\ProviderInterface;
use MasterPuffin\PaymentGateways\Status;
use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\Logging\LoggingConfigurationBuilder;
use PaypalServerSdkLib\Logging\RequestLoggingConfigurationBuilder;
use PaypalServerSdkLib\Logging\ResponseLoggingConfigurationBuilder;
use PaypalServerSdkLib\Models\Builders\AmountWithBreakdownBuilder;
use PaypalServerSdkLib\Models\Builders\OrderApplicationContextBuilder;
use PaypalServerSdkLib\Models\Builders\OrderRequestBuilder;
use PaypalServerSdkLib\Models\Builders\PurchaseUnitRequestBuilder;
use PaypalServerSdkLib\Models\CheckoutPaymentIntent;
use PaypalServerSdkLib\Models\OrderApplicationContextUserAction;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
use Psr\Log\LogLevel;

class PayPal extends Base implements ProviderInterface {
	/**
	 * @param Payment $payment
	 */
	public function create(Payment $payment): ?string {
		// Initialize the PayPal client
		$client = PaypalServerSdkClientBuilder::init()
			->clientCredentialsAuthCredentials(
				ClientCredentialsAuthCredentialsBuilder::init(
					$this->credentials['client_id'],
					$this->credentials['client_secret']
				)
			)
			->environment($this->sandbox ? Environment::SANDBOX : Environment::PRODUCTION)
			->build();

		$collect = [
			'body' => OrderRequestBuilder::init(
				CheckoutPaymentIntent::CAPTURE,
				[
					PurchaseUnitRequestBuilder::init(
						AmountWithBreakdownBuilder::init(
							$payment->getCurrencyCode(),
							$payment->getAmount()
						)->build()
					)->build()
				]
			)
				->applicationContext(
					OrderApplicationContextBuilder::init()
						->returnUrl($this->successUrl)
						->cancelUrl($this->cancelUrl)
						->userAction(OrderApplicationContextUserAction::PAY_NOW)
						->build()
				)
				->build(),
			'prefer' => 'return=minimal'
		];
		$ordersController = $client->getOrdersController();
		$apiResponse = $ordersController->ordersCreate($collect);
		if (is_array($apiResponse->getResult())) {
			throw new GatewayException($apiResponse->getResult()['message']);
		}
		foreach ($apiResponse->getResult()->getLinks() as $link) {
			if ($link->getRel() === 'approve') {
				return $link->getHref();
			}
		}
		throw new GatewayException("PayPal API returned no approve link");
	}

	/**
	 * @param Payment $payment
	 * @throws NotImplementedException
	 */
	public function execute(Payment $payment): Status {
		throw new NotImplementedException("handleWebhook is not implemented");
	}

	/**
	 * @throws NotImplementedException
	 */
	public function refund(Payment $payment): void {
		throw new NotImplementedException("handleWebhook is not implemented");
	}

	/**
	 * @throws NotImplementedException
	 */
	public function getStatusFromWebhook(Payment $payment, string $payload): Status {
		throw new NotImplementedException("handleWebhook is not implemented");
	}
}