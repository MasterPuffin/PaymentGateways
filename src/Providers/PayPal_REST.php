<?php

namespace MasterPuffin\PaymentGateways\Providers;


use Exception;
use MasterPuffin\PaymentGateways\Exceptions\GatewayException;
use MasterPuffin\PaymentGateways\Payment;
use MasterPuffin\PaymentGateways\ProviderInterface;
use MasterPuffin\PaymentGateways\Status;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use PayPalCheckoutSdk\Payments\CapturesGetRequest;
use PayPalCheckoutSdk\Payments\CapturesRefundRequest;
use Psr\Http\Message\RequestInterface;
use Throwable;

class PayPal_REST extends Base implements ProviderInterface {
	/**
	 * @throws GatewayException
	 */
	public function create(Payment $payment): ?string {
		$client = $this->_createClient();
		$request = new OrdersCreateRequest();
		$request->prefer('return=representation');

		$shipping = $payer = [
			'email_address' => $payment->getCustomer()->getEmail(),
		];

		$request->body = [
			"intent" => "CAPTURE",
			"payer" => $payer,
			'purchase_units' =>
				[
					[
						'description' => $payment->getDescription(),
						'amount' =>
							[
								'currency_code' => $payment->getCurrencyCode(),
								'value' => $payment->getAmount(),
								'breakdown' => [
									'item_total' =>
										[
											'currency_code' => $payment->getCurrencyCode(),
											'value' => $payment->getAmount(),
										],
								],],
						'shipping' => $shipping,
					],
				],
			"application_context" => [
				'landing_page' => 'BILLING',
				'shipping_preference' => 'NO_SHIPPING',
				'user_action' => 'PAY_NOW',
				"cancel_url" => $this->cancelUrl,
				"return_url" => $this->successUrl,
				...(array_key_exists('application_context', $this->options) ? $this->options['application_context'] : []),
			],
		];


		try {
			$response = $client->execute($request);
			foreach ($response->result->links as $link) {
				if ($link->rel === "approve") {
					$payment->setProviderId($response->result->id);
					return $link->href;
				}
			}
			throw new GatewayException('No approve link found');
		} catch (Throwable $e) {
			throw new GatewayException($this->_getErrorMessage($e));
		}
	}

	/**
	 * @throws GatewayException
	 */
	public function execute(Payment $payment): Status {
		$client = $this->_createClient();
		$request = new OrdersCaptureRequest($payment->getProviderId());
		$request->prefer('return=representation');

		try {
			$response = $client->execute($request);
		} catch (Exception $e) {
			throw new GatewayException($this->_getErrorMessage($e));
		}

		$payment->setProviderId($response->result->purchase_units[0]->payments->captures[0]->id ?? $response->result->id);
		$payment->setStatus($this->_mapToStatus($response->result->status));
		return $payment->getStatus();
	}

	/**
	 * @throws GatewayException
	 */
	public function refund(Payment $payment, ?float $amount = null): void {
		$isFullRefund = is_null($amount);
		$client = $this->_createClient();
		$request = new CapturesRefundRequest($payment->getProviderId());
		$request->body = array(
			'amount' =>
				[
					'value' => $isFullRefund ? $payment->getAmount() : $amount,
					'currency_code' => $payment->getCurrencyCode()
				]
		);

		try {
			$response = $client->execute($request);
		} catch (Exception $e) {
			throw new GatewayException($this->_getErrorMessage($e));
		}
		if ($response->statusCode !== 201 || $response->result->status !== 'COMPLETED') {
			throw new GatewayException("Error during refund :" . $response->result->status);
		}
	}


	/**
	 * @throws GatewayException
	 */
	public function getStatusFromWebhook(Payment $payment, RequestInterface|null $request = null): Status {
		$payload = json_decode($request->getBody()->getContents());
		if (!in_array($payload->event_type, [
			'PAYMENT.CAPTURE.COMPLETED',
			'PAYMENT.CAPTURE.DENIED',
			'PAYMENT.CAPTURE.REFUNDED',
			'PAYMENT.CAPTURE.REVERSED',
			'CHECKOUT.ORDER.APPROVED',
			'CUSTOMER.DISPUTE.CREATED'
		])) {
			// If the event is not related to a Payment Intent, do nothing
			return $payment->getStatus();
		}
		$client = $this->_createClient();
		try {
			// Create a request to get the order details
			$orderRequest = new CapturesGetRequest($payment->getProviderId());

			$response = $client->execute($orderRequest);
			//TODO there is no way the check if a dispute has been created

			return $this->_mapToStatus($response->result->status);
		} catch (Throwable $e) {
			throw new GatewayException($this->_getErrorMessage($e));
		}
	}


	private function _createClient(): PayPalHttpClient {
		if ($this->sandbox) {
			$environment = new SandboxEnvironment($this->credentials['client_id'], $this->credentials['client_secret']);
		} else {
			$environment = new ProductionEnvironment($this->credentials['client_id'], $this->credentials['client_secret']);
		}
		return new PayPalHttpClient($environment);
	}

	private function _getErrorMessage(Exception $e): string {
		$msgObjStr = $e->getMessage();
		$msg = json_decode($msgObjStr);
		if (property_exists($msg, 'error_description')) {
			return $msg->error_description;
		}

		return $msg->details[0]->issue;
	}

	private function _mapToStatus(string $status): Status {
		switch ($status) {
			case 'COMPLETED':
				return Status::Succeeded;
			case 'PENDING':
				return Status::Pending;
			default:
			case 'DECLINED':
			case 'FAILED':
				return Status::Failed;
			case 'PARTIALLY_REFUNDED':
				return Status::PartiallyRefunded;
			case 'REFUNDED':
				return Status::Refunded;
		}
	}
}