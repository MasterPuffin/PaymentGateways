<?php

namespace MasterPuffin\PaymentGateways\Providers;

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
use PaypalServerSdkLib\Models\Builders\OrderRequestBuilder;
use PaypalServerSdkLib\Models\Builders\PurchaseUnitRequestBuilder;
use PaypalServerSdkLib\Models\CheckoutPaymentIntent;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
use Psr\Log\LogLevel;

class PayPal implements ProviderInterface {
	/**
	 * @param Payment $payment
	 */
	public function create(Payment $payment): ?string {
		// Initialize the PayPal client
		$client = PaypalServerSdkClientBuilder::init()
			->clientCredentialsAuthCredentials(
				ClientCredentialsAuthCredentialsBuilder::init(
					'OAuthClientId',
					'OAuthClientSecret'
				)
			)
			->environment(Environment::SANDBOX)
			->loggingConfiguration(
				LoggingConfigurationBuilder::init()
					->level(LogLevel::INFO)
					->requestConfiguration(RequestLoggingConfigurationBuilder::init()->body(true))
					->responseConfiguration(ResponseLoggingConfigurationBuilder::init()->headers(true))
			)
			->build();

		$collect = [
			'body' => OrderRequestBuilder::init(
				CheckoutPaymentIntent::CAPTURE,
				[
					PurchaseUnitRequestBuilder::init(
						AmountWithBreakdownBuilder::init(
							'currency_code6',
							'value0'
						)->build()
					)->build()
				]
			)->build(),
			'prefer' => 'return=minimal'
		];
		$ordersController = $client->getOrdersController();
		$apiResponse = $ordersController->ordersCreate($collect);
		return '';
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