<?php

namespace MasterPuffin\PaymentGateways\Providers;

use MasterPuffin\PaymentGateways\Exceptions\GatewayException;
use MasterPuffin\PaymentGateways\Exceptions\NotImplementedException;
use MasterPuffin\PaymentGateways\Payment;
use MasterPuffin\PaymentGateways\ProviderInterface;
use MasterPuffin\PaymentGateways\Status;
use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\Exceptions\ApiException;
use PaypalServerSdkLib\Logging\LoggingConfigurationBuilder;
use PaypalServerSdkLib\Logging\RequestLoggingConfigurationBuilder;
use PaypalServerSdkLib\Logging\ResponseLoggingConfigurationBuilder;
use PaypalServerSdkLib\Models\Builders\AmountWithBreakdownBuilder;
use PaypalServerSdkLib\Models\Builders\OrderApplicationContextBuilder;
use PaypalServerSdkLib\Models\Builders\OrderCaptureRequestBuilder;
use PaypalServerSdkLib\Models\Builders\OrderRequestBuilder;
use PaypalServerSdkLib\Models\Builders\PurchaseUnitRequestBuilder;
use PaypalServerSdkLib\Models\CheckoutPaymentIntent;
use PaypalServerSdkLib\Models\OrderApplicationContextUserAction;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
use Psr\Log\LogLevel;
use Throwable;

class PayPal extends Base implements ProviderInterface {
	/**
	 * @param Payment $payment
	 * @throws GatewayException
	 */
	public function create(Payment $payment): ?string {
		// Initialize the PayPal client
		try {
			$client = $this->_buildClient();

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
			//TODO get payment intent id
			foreach ($apiResponse->getResult()->getLinks() as $link) {
				if ($link->getRel() === 'approve') {
					return $link->getHref();
				}
			}
			throw new GatewayException("PayPal API returned no approve link");
		} catch (Throwable $e) {
			throw new GatewayException($e->getMessage());
		}
	}

	/**
	 * @param Payment $payment
	 * @return Status
	 * @throws GatewayException
	 */
	public function execute(Payment $payment): Status {
		try {
			// Initialize the PayPal client
			$client = $this->_buildClient();

			// Set up capture request
			$options = [
				'id' => $payment->getProviderId(),
				'prefer' => 'return=representation',
				'body' => OrderCaptureRequestBuilder::init()->build()
			];

			// Attempt to capture the order
			$ordersController = $client->getOrdersController();
			$response = $ordersController->ordersCapture($options);
			$result = $response->getResult();

			// Validate the capture response
			if (is_array($result)) {
				throw new GatewayException($result['message'] ?? 'Invalid capture response');
			}

			// Check if the capture was successful
			if ($result->getStatus() !== 'COMPLETED') {
				return Status::Failed;
			}
			return Status::Succeeded;
		} catch (ApiException $e) {
			throw new GatewayException(
				"PayPal API error: {$e->getMessage()}",
				$e->getCode(),
				$e
			);
		} catch (\Exception $e) {
			throw new GatewayException(
				"Failed to capture order: {$e->getMessage()}",
				$e->getCode(),
				$e
			);
		}
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

	private function _buildClient(): PaypalServerSdkClient {
		return PaypalServerSdkClientBuilder::init()
			->clientCredentialsAuthCredentials(
				ClientCredentialsAuthCredentialsBuilder::init(
					$this->credentials['client_id'],
					$this->credentials['client_secret']
				)
			)
			->environment($this->sandbox ? Environment::SANDBOX : Environment::PRODUCTION)
			->build();
	}
}