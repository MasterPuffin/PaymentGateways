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
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use PayPalCheckoutSdk\Orders\OrdersAuthorizeRequest;
use PayPalCheckoutSdk\Payments\AuthorizationsCaptureRequest;
use PayPalCheckoutSdk\Payments\AuthorizationsVoidRequest;
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
			'intent' => 'AUTHORIZE',   // 👈 switched to AUTHORIZE
			'payer' => $payer,
			'purchase_units' => [[
				'description' => $payment->getDescription(),
				'amount' => [
					'currency_code' => $payment->getCurrencyCode(),
					'value' => $payment->getAmount(),
					'breakdown' => [
						'item_total' => [
							'currency_code' => $payment->getCurrencyCode(),
							'value' => $payment->getAmount(),
						],
					],
				],
				'shipping' => $shipping,
			]],
			'application_context' => [
				'landing_page' => 'BILLING',
				'shipping_preference' => 'NO_SHIPPING',
				'user_action' => 'PAY_NOW',
				'cancel_url' => $this->cancelUrl,
				'return_url' => $this->successUrl,
				...(array_key_exists('application_context', $this->options) ? $this->options['application_context'] : []),
			],
		];

		try {
			$response = $client->execute($request);
			foreach ($response->result->links as $link) {
				if ($link->rel === 'approve') {
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
	 * Authorize and immediately capture a PayPal order
	 * @throws GatewayException
	 */
	public function execute(Payment $payment): Status {
		$client = $this->_createClient();

		// Step 1: authorize the order
		$authRequest = new OrdersAuthorizeRequest($payment->getProviderId());
		$authRequest->prefer('return=representation');

		try {
			$authResponse = $client->execute($authRequest);
		} catch (Exception $e) {
			throw new GatewayException($this->_getErrorMessage($e));
		}

		$authId = $authResponse->result
			->purchase_units[0]->payments->authorizations[0]->id ?? null;

		if (!$authId) {
			throw new GatewayException("No authorization ID returned from PayPal");
		}

		// Step 2: capture the authorization
		$captureRequest = new AuthorizationsCaptureRequest($authId);
		$captureRequest->prefer('return=representation');

		try {
			$captureResponse = $client->execute($captureRequest);
		} catch (Exception $e) {
			throw new GatewayException($this->_getErrorMessage($e));
		}

		$captureId = $captureResponse->result->id ?? null;
		$status = $this->_mapToStatus($captureResponse->result->status);

		if (!$captureId) {
			throw new GatewayException("No capture ID returned from PayPal");
		}

		$payment->setProviderId($captureId);
		$payment->setStatus($status);

		return $payment->getStatus();
	}

	/**
	 * Cancel (void) an authorized payment before capture.
	 * @throws GatewayException
	 */
	public function cancel(Payment $payment): void {
		$client = $this->_createClient();
		$request = new AuthorizationsVoidRequest($payment->getProviderId());

		try {
			$response = $client->execute($request);
			if ($response->statusCode === 204) {
				$payment->setStatus(Status::Cancelled);
			} else {
				throw new GatewayException('Cancel failed with status: ' . $response->statusCode);
			}
		} catch (Throwable $e) {
			throw new GatewayException($this->_getErrorMessage($e));
		}
	}

	/**
	 * Refund a captured payment
	 * @throws GatewayException
	 */
	public function refund(Payment $payment, ?float $amount = null): void {
		$isFullRefund = is_null($amount);
		$client = $this->_createClient();
		$request = new CapturesRefundRequest($payment->getProviderId());
		$request->body = [
			'amount' => [
				'value' => $isFullRefund ? $payment->getAmount() : $amount,
				'currency_code' => $payment->getCurrencyCode(),
			],
		];

		try {
			$response = $client->execute($request);
		} catch (Exception $e) {
			throw new GatewayException($this->_getErrorMessage($e));
		}
		if ($response->statusCode !== 201 || $response->result->status !== 'COMPLETED') {
			throw new GatewayException('Error during refund: ' . $response->result->status);
		}

		$payment->setStatus($isFullRefund ? Status::Refunded : Status::PartiallyRefunded);
	}

	/**
	 * @throws GatewayException
	 */
	public function getStatusFromWebhook(Payment $payment, RequestInterface|null $request = null): Status {
		$payload = json_decode($request->getBody()->getContents());
		if (!in_array($payload->event_type, [
			'PAYMENT.AUTHORIZATION.CREATED',
			'PAYMENT.AUTHORIZATION.VOIDED',
			'PAYMENT.CAPTURE.COMPLETED',
			'PAYMENT.CAPTURE.DENIED',
			'PAYMENT.CAPTURE.REFUNDED',
			'PAYMENT.CAPTURE.REVERSED',
			'CUSTOMER.DISPUTE.CREATED',
		])) {
			return $payment->getStatus();
		}

		$client = $this->_createClient();
		try {
			$orderRequest = new CapturesGetRequest($payment->getProviderId());
			$response = $client->execute($orderRequest);
			return $this->_mapToStatus($response->result->status);
		} catch (Throwable $e) {
			throw new GatewayException($this->_getErrorMessage($e));
		}
	}

	private function _createClient(): PayPalHttpClient {
		if ($this->sandbox) {
			$env = new SandboxEnvironment(
				$this->credentials['client_id'],
				$this->credentials['client_secret']
			);
		} else {
			$env = new ProductionEnvironment(
				$this->credentials['client_id'],
				$this->credentials['client_secret']
			);
		}
		return new PayPalHttpClient($env);
	}

	private function _getErrorMessage(Exception $e): string {
		$msgObjStr = $e->getMessage();
		$msg = json_decode($msgObjStr);
		if ($msg && property_exists($msg, 'error_description')) {
			return $msg->error_description;
		}
		if ($msg && isset($msg->details[0]->issue)) {
			return $msg->details[0]->issue;
		}
		return $e->getMessage();
	}

	private function _mapToStatus(string $status): Status {
		switch ($status) {
			case 'COMPLETED':
				return Status::Succeeded;
			case 'PENDING':
				return Status::Pending;
			case 'REFUNDED':
				return Status::Refunded;
			case 'PARTIALLY_REFUNDED':
				return Status::PartiallyRefunded;
			case 'DECLINED':
			case 'FAILED':
			case 'VOIDED':
				return Status::Failed;
			case 'AUTHORIZED':
				return Status::Authorized;
			default:
				return Status::Pending;
		}
	}
}