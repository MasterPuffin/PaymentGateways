<?php

namespace MasterPuffin\PaymentGateways\Providers;

use Exception;
use MasterPuffin\PaymentGateways\Exceptions\GatewayException;
use MasterPuffin\PaymentGateways\Payment;
use MasterPuffin\PaymentGateways\ProviderInterface;
use MasterPuffin\PaymentGateways\Status;
use MasterPuffin\PaymentGateways\WebhookEvent;
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
	/** Outcomes of a chargeback in which the seller keeps the money */
	private const array DISPUTE_WON = ['won', 'warning_closed'];
	/** Events of a dispute that is open; a non-inquiry dispute withdraws the disputed amount */
	private const array DISPUTE_OPENED = ['charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.funds_withdrawn'];

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
				'unit_amount' => $this->_toMinorUnits($payment->getAmount(), $payment->getCurrencyCode()),
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
	 * Settles the payment: resolves a Checkout session to its payment intent (the payment's provider id changes) and
	 * captures an authorized payment. A session the customer has not completed yet is Open, an expired one Cancelled.
	 * A payment that was refunded or disputed meanwhile is reported as such ({@see fetchStatus()}): a payment intent
	 * stays `succeeded` after a refund. Call {@see fetchStatus()} for the refunded total and the dispute.
	 *
	 * @throws GatewayException
	 */
	public function execute(Payment $payment): Status {
		Stripe::setApiKey($this->credentials['secret_key']);

		$sessionStatus = $this->_resolveSession($payment);
		if (!is_null($sessionStatus)) {
			$payment->setStatus($sessionStatus);
			return $sessionStatus;
		}
		$paymentIntent = $this->_retrievePaymentIntent($payment->getProviderId());

		if ($paymentIntent->status === 'requires_capture') {
			try {
				$paymentIntent->capture();
				$payment->setStatus(Status::Succeeded);
				return $payment->getStatus();
			} catch (Throwable $e) {
				throw new GatewayException("Capture failed: " . $e->getMessage());
			}
		}

		$payment->setAmount($this->_fromMinorUnits($paymentIntent->amount, $paymentIntent->currency));
		$payment->setStatus($this->_eventFromPaymentIntent($payment, $paymentIntent)->status);
		return $payment->getStatus();
	}

	/**
	 * Reads the current state of the payment from Stripe without changing anything (no capture, the session is not
	 * expired). The provider id of a completed Checkout session changes to its payment intent; a session without
	 * payment intent is Open, or Cancelled once expired.
	 *
	 * A payment intent stays `succeeded` after refunds and chargebacks, so the charge decides:
	 *  - refunded: Refunded or PartiallyRefunded with the sum of all refunds;
	 *  - an open or lost dispute (not an inquiry): Disputed with the disputed amount and the dispute id;
	 *  - a dispute closed in favour of the seller ends a dispute the payment is stored with ({@see WebhookEvent::$disputeWon}),
	 *    or one whose funds came back (`won`); a closed inquiry of an undisputed payment moved no money and is Succeeded.
	 *
	 * @throws GatewayException
	 */
	public function fetchStatus(Payment $payment): WebhookEvent {
		Stripe::setApiKey($this->credentials['secret_key']);

		$sessionStatus = $this->_resolveSession($payment);
		if (!is_null($sessionStatus)) {
			return new WebhookEvent($sessionStatus);
		}
		return $this->_eventFromPaymentIntent($payment, $this->_retrievePaymentIntent($payment->getProviderId()));
	}

	/**
	 * The payment intent with its latest charge and the charge's dispute. Protected, so tests can answer without Stripe.
	 *
	 * @throws GatewayException
	 */
	protected function _retrievePaymentIntent(string $id): PaymentIntent {
		try {
			return PaymentIntent::retrieve(['id' => $id, 'expand' => ['latest_charge.dispute']]);
		} catch (Throwable $e) {
			throw new GatewayException($e->getMessage());
		}
	}

	/**
	 * State of a payment intent including refunds and the dispute of its charge, see {@see fetchStatus()}.
	 */
	private function _eventFromPaymentIntent(Payment $payment, PaymentIntent $paymentIntent): WebhookEvent {
		$status = $this->_mapStatus((string)$paymentIntent->status);
		$charge = is_object($paymentIntent->latest_charge ?? null) ? $paymentIntent->latest_charge : null;
		if ($status !== Status::Succeeded || is_null($charge)) {
			return new WebhookEvent($status);
		}
		$currency = (string)($charge->currency ?? $paymentIntent->currency);

		$dispute = is_object($charge->dispute ?? null) ? $charge->dispute : null;
		if (!is_null($dispute) && !self::_isInquiry($dispute) && !in_array($dispute->status ?? null, self::DISPUTE_WON, true)) {
			return new WebhookEvent(Status::Disputed, disputedAmount: $this->_fromMinorUnits((int)$dispute->amount, $currency), disputeId: $dispute->id);
		}
		$won = !is_null($dispute) && in_array($dispute->status ?? null, self::DISPUTE_WON, true)
			&& ($payment->getStatus() === Status::Disputed || $dispute->status === 'won');

		$refundedTotal = $this->_fromMinorUnits((int)($charge->amount_refunded ?? 0), $currency);
		if ($refundedTotal > 0) {
			//A refund after a won dispute: the refund is the newer state, the caller books the dispute's end on its own report
			return new WebhookEvent(($charge->refunded ?? false) ? Status::Refunded : Status::PartiallyRefunded, refundedTotal: $refundedTotal);
		}
		if ($won) {
			return new WebhookEvent(Status::Succeeded, disputedAmount: $dispute->status === 'won' ? $this->_fromMinorUnits((int)$dispute->amount, $currency) : null, disputeWon: true, disputeId: $dispute->id);
		}
		return new WebhookEvent(Status::Succeeded);
	}

	/**
	 * Cancel a payment (works only if not yet fully processed/captured). An open Checkout session is expired, so the
	 * customer can no longer pay it.
	 *
	 * @throws GatewayException
	 */
	public function cancel(Payment $payment): void {
		Stripe::setApiKey($this->credentials['secret_key']);

		if (str_starts_with((string)$payment->getProviderId(), 'cs_')) {
			try {
				$session = Session::retrieve($payment->getProviderId());
				if ($session->status === 'open') {
					$session = $session->expire();
				}
			} catch (Throwable $e) {
				throw new GatewayException("Cancel failed: " . $e->getMessage());
			}
			if ($session->status !== 'expired') {
				throw new GatewayException("Checkout session cannot be cancelled (current status: {$session->status})");
			}
			$payment->setStatus(Status::Cancelled);
			return;
		}

		try {
			$intent = PaymentIntent::retrieve($payment->getProviderId());

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
			$options['amount'] = $this->_toMinorUnits($amount, $payment->getCurrencyCode());
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

			//An inquiry (status warning_*) withdraws no funds, the payment keeps its status until it is escalated
			if (in_array($event->type, self::DISPUTE_OPENED, true)) {
				return self::_isInquiry($event->data->object) ? $payment->getStatus() : Status::Disputed;
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
	 * Verifies the event and maps it: payment intent events to their status, `checkout.session.completed` to a checkout
	 * to settle with {@see execute()}, `checkout.session.expired` to Cancelled, `charge.refunded` with the sum of all
	 * refunds of the charge (also of refunds made in the Stripe dashboard). While a payment is disputed only the closing
	 * of the dispute changes it, a repeated `payment_intent.succeeded` doesn't. Other events keep the payment's status.
	 *
	 * Dispute events carry the dispute id ({@see WebhookEvent::$disputeId}), events arrive in any order and repeatedly:
	 *  - `charge.dispute.created`, `.updated` and `.funds_withdrawn` report Disputed with the disputed amount. An inquiry
	 *    (status `warning_needs_response`, `warning_under_review`) withdraws no funds and keeps the payment's status;
	 *    when it is escalated, the same dispute is updated to `needs_response`.
	 *  - `charge.dispute.closed` won reports Succeeded with {@see WebhookEvent::$disputeWon} and the amount that comes
	 *    back, `warning_closed` (an inquiry that ended without chargeback) with no amount, as no funds moved. Lost
	 *    reports Disputed with the amount, so a lost dispute whose opening never arrived is still booked.
	 *
	 * @throws GatewayException The signature is invalid
	 */
	public function parseWebhook(Payment $payment, RequestInterface $request): WebhookEvent {
		$status = $this->getStatusFromWebhook($payment, $request);
		$event = json_decode($this->_getWebhookBody($request));
		$object = $event->data->object ?? null;
		$currency = (string)($object->currency ?? $payment->getCurrencyCode());
		$amount = fn(string $field) => is_numeric($object->$field ?? null) ? $this->_fromMinorUnits($object->$field, $currency) : null;
		$disputeId = is_string($object->id ?? null) ? $object->id : null;
		$type = $event->type ?? null;

		if (in_array($type, self::DISPUTE_OPENED, true)) {
			return self::_isInquiry($object) ? new WebhookEvent($status, disputeId: $disputeId) : new WebhookEvent(Status::Disputed, disputedAmount: $amount('amount'), disputeId: $disputeId);
		}
		if ($type === 'charge.dispute.closed') {
			$closedAs = $object->status ?? null;
			if (!in_array($closedAs, self::DISPUTE_WON, true)) {
				return new WebhookEvent(Status::Disputed, disputedAmount: $amount('amount'), disputeId: $disputeId);
			}
			return new WebhookEvent(Status::Succeeded, disputedAmount: $closedAs === 'won' ? $amount('amount') : null, disputeWon: true, disputeId: $disputeId);
		}
		return match ($type) {
			'checkout.session.completed' => new WebhookEvent($payment->getStatus(), checkoutCompleted: true),
			'checkout.session.expired' => new WebhookEvent(Status::Cancelled),
			'charge.refunded' => new WebhookEvent($status, refundedTotal: $amount('amount_refunded')),
			default => new WebhookEvent($payment->getStatus() === Status::Disputed ? Status::Disputed : $status),
		};
	}

	/**
	 * Whether the dispute is an inquiry (status `warning_*`): the customer's bank asks, no funds are withdrawn.
	 */
	private static function _isInquiry(?object $dispute): bool {
		return str_starts_with((string)($dispute->status ?? ''), 'warning_');
	}

	/**
	 * The Checkout session (session events) and the payment intent the event refers to.
	 */
	public function getProviderIdsFromWebhook(RequestInterface $request): array {
		$object = json_decode((string)$request->getBody())->data->object ?? null;
		$session = $object->id ?? null;
		return array_values(array_unique(array_filter([
			is_string($session) && str_starts_with($session, 'cs_') ? $session : null,
			$this->getProviderIdFromWebhook($request),
		], 'is_string')));
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
	 * Replaces a Checkout session id with the id of its payment intent. Returns the status of a session that has none
	 * (Open, or Cancelled once expired), null once the payment intent is the provider id.
	 *
	 * @throws GatewayException
	 */
	private function _resolveSession(Payment $payment): ?Status {
		if (!str_starts_with((string)$payment->getProviderId(), 'cs_')) {
			return null;
		}
		try {
			$session = Session::retrieve($payment->getProviderId());
		} catch (Throwable $e) {
			throw new GatewayException($e->getMessage());
		}
		if (empty($session->payment_intent)) {
			return $session->status === 'expired' ? Status::Cancelled : Status::Open;
		}
		$payment->setProviderId(is_string($session->payment_intent) ? $session->payment_intent : $session->payment_intent->id);
		return null;
	}
}