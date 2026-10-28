<?php

namespace MasterPuffin\PaymentGateways\Providers;

use Exception;
use MasterPuffin\PaymentGateways\Exceptions\GatewayException;
use MasterPuffin\PaymentGateways\Payment;
use MasterPuffin\PaymentGateways\ProviderInterface;
use MasterPuffin\PaymentGateways\Status;
use Psr\Http\Message\RequestInterface;
use Stripe\Charge;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\Stripe;
use Stripe\Webhook;
use Throwable;

class Stripe_Checkout extends Base implements ProviderInterface {

	/**
	 * @throws GatewayException
	 */
	public function create(Payment $payment): string {
		Stripe::setApiKey($this->credentials['secret_key']);

		$line_items = [[
			'price_data' => [
				'currency' => strtolower($payment->getCurrencyCode()),
				'product_data' => [
					'name' => $payment->getDescription(),
				],
				'unit_amount' => $this->_toMinorUnits($payment->getAmount()),
			],
			'quantity' => 1
		]];

		$options = [
			'customer_email' => $payment->getCustomer()->getEmail(),
			'line_items' => $line_items,
			'mode' => 'payment',
			'success_url' => $this->successUrl,
			'cancel_url' => $this->cancelUrl,
			'payment_intent_data' => [
				'capture_method' => $this->_supportsManualCapture() ? 'manual' : 'automatic',
				'metadata' => $payment->getMetadata(),
			]
		];
		//Called allowed_payment_method_types since API version 2026-09-30.endive (stripe-php 22)
		if (!empty(($this->options['payment_method_types']))) {
			$options['allowed_payment_method_types'] = $this->options['payment_method_types'];
		}

		try {
			$session = Session::create($options);
		} catch (Throwable $e) {
			throw new GatewayException($e->getMessage());
		}

		$payment->setProviderId($session->id);
		return $session->url;
	}

	/**
	 * @throws GatewayException
	 */
	public function execute(Payment $payment): Status {
		Stripe::setApiKey($this->credentials['secret_key']);

		try {
			$paymentIntent = PaymentIntent::retrieve($this->_getPaymentIntentId($payment));
		} catch (Throwable $e) {
			throw new GatewayException($e->getMessage());
		}

		if ($paymentIntent->status === 'requires_capture') {
			try {
				$paymentIntent->capture();
				$payment->setStatus(Status::Succeeded);
				return $payment->getStatus();
			} catch (Throwable $e) {
				throw new GatewayException("Capture failed: " . $e->getMessage());
			}
		}

		$payment->setAmount($paymentIntent->amount / 100);
		$payment->setStatus($this->_mapStatus($paymentIntent->status));
		return $payment->getStatus();
	}

	/**
	 * Cancel a payment (works only if not yet fully processed/captured).
	 *
	 * @throws GatewayException
	 */
	public function cancel(Payment $payment): void {
		Stripe::setApiKey($this->credentials['secret_key']);

		try {
			$intent = PaymentIntent::retrieve($this->_getPaymentIntentId($payment));

			// Only try to cancel if it's still cancelable
			if (in_array($intent->status, ['requires_payment_method', 'requires_confirmation', 'requires_capture', 'processing'], true)) {
				$intent->cancel();
				$payment->setStatus(Status::Cancelled);
			} else {
				throw new GatewayException("Payment cannot be cancelled (current status: {$intent->status})");
			}
		} catch (Throwable $e) {
			throw new GatewayException("Cancel failed: " . $e->getMessage());
		}
	}

	/**
	 * @throws GatewayException
	 */
	public function refund(Payment $payment, ?float $amount = null): void {
		$isFullRefund = $this->_isFullRefund($payment, $amount);
		Stripe::setApiKey($this->credentials['secret_key']);
		$options = [
			'payment_intent' => $payment->getProviderId(),
		];
		if (!is_null($amount)) {
			$options['amount'] = $this->_toMinorUnits($amount);
		}
		try {
			$refund = Refund::create($options);
		} catch (Throwable $e) {
			throw new GatewayException($e->getMessage());
		}

		//Earlier partial refunds count too, the charge knows whether everything has been refunded by now
		try {
			$isFullRefund = Charge::retrieve($refund->charge)->refunded;
		} catch (Throwable) {
		}
		$payment->setStatus($isFullRefund ? Status::Refunded : Status::PartiallyRefunded);
	}

	/**
	 * @throws GatewayException
	 */
	public function getStatusFromWebhook(Payment $payment, RequestInterface|null $request = null): Status {
		$body = $this->_getWebhookBody($request);
		Stripe::setApiKey($this->credentials['secret_key']);
		try {
			$event = Webhook::constructEvent(
				$body,
				$request->getHeaderLine('Stripe-Signature'),
				$this->credentials['webhook_secret']
			);

			if ($event->type === 'payment_intent.succeeded' ||
				$event->type === 'payment_intent.payment_failed' ||
				$event->type === 'payment_intent.processing' ||
				$event->type === 'payment_intent.canceled') {

				$paymentIntent = $event->data->object;
				$status = $paymentIntent->status;

				return $this->_mapStatus($status);
			}

			if ($event->type === 'charge.dispute.created') {
				return Status::Disputed;
			}

			//The charge carries the sum of all refunds, a single refund.created event only its own amount
			if ($event->type === 'charge.refunded') {
				$charge = $event->data->object;
				return $charge->refunded ? Status::Refunded : Status::PartiallyRefunded;
			}

			return $payment->getStatus();
		} catch (SignatureVerificationException|Exception $e) {
			throw new GatewayException($e->getMessage());
		}
	}

	/**
	 * Payment intent the event refers to: charges, refunds and disputes reference it, payment intent events are it.
	 */
	public function getProviderIdFromWebhook(RequestInterface $request): ?string {
		$object = json_decode((string)$request->getBody())->data->object ?? null;
		$id = $object->payment_intent ?? $object->id ?? null;
		return is_string($id) ? $id : null;
	}

	private function _mapStatus(string $status): Status {
		switch ($status) {
			case 'canceled':
				return Status::Failed;
			case 'requires_action':
			case 'requires_payment_method':
			case 'requires_confirmation':
			case 'processing':
				return Status::Pending;
			case 'succeeded':
				return Status::Succeeded;
			case 'requires_capture':
				return Status::Authorized;
			default:
				return Status::Failed;
		}
	}

	private function _supportsManualCapture(): bool {
		//https://docs.stripe.com/payments/payment-methods/payment-method-support
		$supported = [
			'affirm',
			'afterpay_clearpay',
			'alma',
			'billie',
			'capchase_pay',
			'klarna',
			'kriya',
			'mondu',
			'sequra',
			'card',
			'link',
			'amazon_pay',
			'cashapp',
			'mobilepay',
			'paypal',
			'revolut_pay',
			'vipps',
		];
		//Without explicit methods Stripe may offer methods that can't be captured manually
		if (empty($this->options['payment_method_types'])) {
			return false;
		}
		foreach ($this->options['payment_method_types'] as $method) {
			if (!in_array($method, $supported)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @throws GatewayException
	 */
	private function _getPaymentIntentId(Payment $payment): string {
		if (str_starts_with($payment->getProviderId(), 'cs_')) {
			try {
				$session = Session::retrieve($payment->getProviderId());
			} catch (Throwable $e) {
				throw new GatewayException($e->getMessage());
			}
			$pi = $session->payment_intent;
			if (empty($pi)) {
				throw new GatewayException("Payment intent not found");
			}
			$payment->setProviderId($pi);
		}
		return $payment->getProviderId();
	}
}