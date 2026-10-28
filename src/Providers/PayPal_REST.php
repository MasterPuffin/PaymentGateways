<?php

namespace MasterPuffin\PaymentGateways\Providers;

use MasterPuffin\PaymentGateways\Exceptions\GatewayException;
use MasterPuffin\PaymentGateways\Payment;
use MasterPuffin\PaymentGateways\ProviderInterface;
use MasterPuffin\PaymentGateways\Status;
use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\Exceptions\ErrorException;
use PaypalServerSdkLib\Http\ApiResponse;
use PaypalServerSdkLib\Models\Builders\AmountBreakdownBuilder;
use PaypalServerSdkLib\Models\Builders\AmountWithBreakdownBuilder;
use PaypalServerSdkLib\Models\Builders\MoneyBuilder;
use PaypalServerSdkLib\Models\Builders\OrderRequestBuilder;
use PaypalServerSdkLib\Models\Builders\PaymentSourceBuilder;
use PaypalServerSdkLib\Models\Builders\PaypalWalletBuilder;
use PaypalServerSdkLib\Models\Builders\PaypalWalletExperienceContextBuilder;
use PaypalServerSdkLib\Models\Builders\PurchaseUnitRequestBuilder;
use PaypalServerSdkLib\Models\Builders\RefundRequestBuilder;
use PaypalServerSdkLib\Models\CheckoutPaymentIntent;
use PaypalServerSdkLib\Models\PaypalExperienceLandingPage;
use PaypalServerSdkLib\Models\PaypalExperienceUserAction;
use PaypalServerSdkLib\Models\PaypalWalletContextShippingPreference;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
use Psr\Http\Message\RequestInterface;
use Throwable;

class PayPal_REST extends Base implements ProviderInterface {
	/**
	 * Creates an order with intent AUTHORIZE, {@see execute()} authorizes and captures it after the buyer approved.
	 * The option `application_context` may set `brand_name` and `locale` of the PayPal checkout page.
	 *
	 * @throws GatewayException
	 */
	public function create(Payment $payment): ?string {
		$amount = $this->_formatAmount($payment->getAmount());
		$currency = $payment->getCurrencyCode();
		$context = $this->options['application_context'] ?? [];

		$experienceContext = PaypalWalletExperienceContextBuilder::init()
			->landingPage(PaypalExperienceLandingPage::GUEST_CHECKOUT)
			->shippingPreference(PaypalWalletContextShippingPreference::NO_SHIPPING)
			->userAction(PaypalExperienceUserAction::PAY_NOW)
			->returnUrl($this->successUrl)
			->cancelUrl($this->cancelUrl)
			->brandName($context['brand_name'] ?? null)
			->locale($context['locale'] ?? null)
			->build();

		$order = OrderRequestBuilder::init(CheckoutPaymentIntent::AUTHORIZE, [
			PurchaseUnitRequestBuilder::init(
				AmountWithBreakdownBuilder::init($currency, $amount)
					->breakdown(AmountBreakdownBuilder::init()->itemTotal(MoneyBuilder::init($currency, $amount)->build())->build())
					->build()
			)->description($payment->getDescription())->build(),
		])->paymentSource(
			PaymentSourceBuilder::init()->paypal(
				PaypalWalletBuilder::init()
					->emailAddress($payment->getCustomer()->getEmail())
					->experienceContext($experienceContext)
					->build()
			)->build()
		)->build();

		$result = $this->_call(fn(PaypalServerSdkClient $client) => $client->getOrdersController()->createOrder([
			'body' => $order,
			'prefer' => 'return=representation',
		]));

		//With a payment source the buyer is sent to "payer-action", older orders used "approve"
		foreach ($result->getLinks() ?? [] as $link) {
			if (in_array($link->getRel(), ['payer-action', 'approve'], true)) {
				$payment->setProviderId($result->getId());
				return $link->getHref();
			}
		}
		throw new GatewayException('No approve link found');
	}

	/**
	 * Authorize and immediately capture a PayPal order
	 * @throws GatewayException
	 */
	public function execute(Payment $payment): Status {
		$authorization = $this->_call(fn(PaypalServerSdkClient $client) => $client->getOrdersController()->authorizeOrder([
			'id' => $payment->getProviderId(),
			'prefer' => 'return=representation',
		]));

		$authorizations = ($authorization->getPurchaseUnits()[0] ?? null)?->getPayments()?->getAuthorizations() ?? [];
		$authId = ($authorizations[0] ?? null)?->getId();
		if (!$authId) {
			throw new GatewayException("No authorization ID returned from PayPal");
		}

		$capture = $this->_call(fn(PaypalServerSdkClient $client) => $client->getPaymentsController()->captureAuthorizedPayment([
			'authorizationId' => $authId,
			'prefer' => 'return=representation',
		]));

		if (!$capture->getId()) {
			throw new GatewayException("No capture ID returned from PayPal");
		}

		$payment->setAmount((float)$capture->getAmount()?->getValue());
		$payment->setProviderId($capture->getId());
		$payment->setStatus($this->_mapToStatus((string)$capture->getStatus()));

		return $payment->getStatus();
	}

	/**
	 * Cancel (void) an authorized payment before capture.
	 * @throws GatewayException
	 */
	public function cancel(Payment $payment): void {
		$this->_call(fn(PaypalServerSdkClient $client) => $client->getPaymentsController()->voidPayment([
			'authorizationId' => $payment->getProviderId(),
		]));
		$payment->setStatus(Status::Cancelled);
	}

	/**
	 * Refund a captured payment
	 * @throws GatewayException
	 */
	public function refund(Payment $payment, ?float $amount = null): void {
		$isFullRefund = $this->_isFullRefund($payment, $amount);

		$refund = $this->_call(fn(PaypalServerSdkClient $client) => $client->getPaymentsController()->refundCapturedPayment([
			'captureId' => $payment->getProviderId(),
			'prefer' => 'return=representation',
			'body' => RefundRequestBuilder::init()
				->amount(MoneyBuilder::init($payment->getCurrencyCode(), $this->_formatAmount($amount ?? $payment->getAmount()))->build())
				->build(),
		]));

		//PENDING refunds (e.g. eCheck) are accepted by PayPal and complete later, treating them as failed invites a second refund
		if (!in_array($refund->getStatus(), ['COMPLETED', 'PENDING'], true)) {
			throw new GatewayException('Error during refund: ' . $refund->getStatus());
		}

		//Earlier partial refunds count too, the capture knows whether everything has been refunded by now
		try {
			$captureStatus = $this->_mapToStatus((string)$this->_getCapture($payment->getProviderId())->getStatus());
			if (in_array($captureStatus, [Status::Refunded, Status::PartiallyRefunded], true)) {
				$isFullRefund = $captureStatus === Status::Refunded;
			}
		} catch (Throwable) {
		}
		$payment->setStatus($isFullRefund ? Status::Refunded : Status::PartiallyRefunded);
	}

	/**
	 * The webhook payload is not trusted (its signature is not checked): the status is always fetched from the PayPal
	 * API for the saved capture, a dispute is only reported if the API confirms an open dispute for this capture.
	 *
	 * @throws GatewayException
	 */
	public function getStatusFromWebhook(Payment $payment, RequestInterface|null $request = null): Status {
		$payload = json_decode($this->_getWebhookBody($request));
		$eventType = $payload->event_type ?? null;
		if (!in_array($eventType, [
			'PAYMENT.AUTHORIZATION.CREATED',
			'PAYMENT.AUTHORIZATION.VOIDED',
			'PAYMENT.CAPTURE.COMPLETED',
			'PAYMENT.CAPTURE.PENDING',
			'PAYMENT.CAPTURE.DENIED',
			'PAYMENT.CAPTURE.REFUNDED',
			'PAYMENT.CAPTURE.REVERSED',
			'CUSTOMER.DISPUTE.CREATED',
			'CUSTOMER.DISPUTE.UPDATED',
			'CUSTOMER.DISPUTE.RESOLVED',
		], true)) {
			return $payment->getStatus();
		}

		if (str_starts_with($eventType, 'CUSTOMER.DISPUTE.') && $this->_hasOpenDispute($payload->resource->dispute_id ?? $payload->resource->id ?? null, $payment->getProviderId())) {
			return Status::Disputed;
		}

		//Resolved disputes and all other events: the capture shows the outcome (e.g. refunded after a lost dispute)
		return $this->_mapToStatus((string)$this->_getCapture($payment->getProviderId())->getStatus());
	}

	/**
	 * Capture id the event refers to. Capture events carry the capture itself, refunds and reversals link to it ("up"),
	 * disputes list it as seller transaction.
	 */
	public function getProviderIdFromWebhook(RequestInterface $request): ?string {
		$resource = json_decode((string)$request->getBody())->resource ?? null;
		if (!is_object($resource)) {
			return null;
		}

		foreach ($resource->links ?? [] as $link) {
			if (($link->rel ?? null) === 'up' && preg_match('#/v2/payments/captures/([^/?]+)#', $link->href ?? '', $match)) {
				return $match[1];
			}
		}

		$disputedTransaction = $resource->disputed_transactions[0]->seller_transaction_id ?? null;
		if (is_string($disputedTransaction)) {
			return $disputedTransaction;
		}

		return is_string($resource->id ?? null) ? $resource->id : null;
	}

	/**
	 * @throws GatewayException
	 */
	private function _getCapture(string $captureId): object {
		return $this->_call(fn(PaypalServerSdkClient $client) => $client->getPaymentsController()->getCapturedPayment([
			'captureId' => $captureId,
		]));
	}

	/**
	 * Whether PayPal knows an unresolved dispute with this id for the given capture. The SDK has no disputes API,
	 * so the request uses the SDK's OAuth token directly.
	 *
	 * @throws GatewayException
	 */
	private function _hasOpenDispute(?string $disputeId, string $captureId): bool {
		if (empty($disputeId)) {
			return false;
		}
		$client = $this->_createClient();
		try {
			$token = $client->getClientCredentialsAuth()->fetchToken()->getAccessToken();
		} catch (Throwable $e) {
			throw new GatewayException($this->_getErrorMessage($e));
		}

		$curl = curl_init($client->getBaseUri() . '/v1/customer/disputes/' . rawurlencode($disputeId));
		curl_setopt_array($curl, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
		]);
		$body = curl_exec($curl);
		$code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
		$dispute = is_string($body) ? json_decode($body) : null;
		if ($code !== 200 || !is_object($dispute)) {
			throw new GatewayException($dispute->name ?? ('Dispute request failed with status ' . $code));
		}

		if (($dispute->status ?? 'RESOLVED') === 'RESOLVED') {
			return false;
		}
		foreach ($dispute->disputed_transactions ?? [] as $transaction) {
			if (($transaction->seller_transaction_id ?? null) === $captureId) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Runs an SDK call and returns its result. The SDK throws for most errors and returns an error response for others.
	 *
	 * @param callable(PaypalServerSdkClient): ApiResponse $call
	 * @throws GatewayException
	 */
	private function _call(callable $call): mixed {
		try {
			$response = $call($this->_createClient());
		} catch (Throwable $e) {
			throw new GatewayException($this->_getErrorMessage($e));
		}
		if ($response->isError()) {
			//Error responses aren't mapped to a model, the reason (e.g. CAPTURE_FULLY_REFUNDED) is in the raw body
			$body = $response->getBody();
			$error = is_string($body) ? json_decode($body) : (is_object($body) ? $body : null);
			throw new GatewayException($error->details[0]->issue ?? $error->name ?? $error->message ?? ('PayPal request failed with status ' . $response->getStatusCode()));
		}
		return $response->getResult();
	}

	private function _createClient(): PaypalServerSdkClient {
		return PaypalServerSdkClientBuilder::init()
			->clientCredentialsAuthCredentials(ClientCredentialsAuthCredentialsBuilder::init(
				$this->credentials['client_id'],
				$this->credentials['client_secret']
			))
			->environment($this->sandbox ? Environment::SANDBOX : Environment::PRODUCTION)
			->timeout(30)
			->build();
	}

	private function _getErrorMessage(Throwable $e): string {
		//API errors carry the reason in their details, e.g. INVALID_RESOURCE_ID
		if ($e instanceof ErrorException) {
			return ($e->getDetails()[0] ?? null)?->getIssue() ?? $e->getMessageProperty();
		}
		$msg = json_decode($e->getMessage());
		if ($msg && property_exists($msg, 'error_description')) {
			return $msg->error_description;
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