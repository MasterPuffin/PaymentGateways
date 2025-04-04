<?php

namespace MasterPuffin\PaymentGateways\Providers;

use Exception;
use MasterPuffin\PaymentGateways\Exceptions\GatewayException;
use MasterPuffin\PaymentGateways\Payment;
use MasterPuffin\PaymentGateways\ProviderInterface;
use MasterPuffin\PaymentGateways\Status;
use Psr\Http\Message\RequestInterface;
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
				'unit_amount' => (int)($payment->getAmount() * 100),
			],
			'quantity' => 1
		]];
		$options = [
			'customer_email' => $payment->getCustomer()->getEmail(),
			'line_items' => $line_items,
			'mode' => 'payment',
			'success_url' => $this->successUrl,
			'cancel_url' => $this->cancelUrl,
			'metadata' => $payment->getMetadata(),
		];
		if (!empty(($this->options['payment_method_types']))) {
			$options['payment_method_types'] = $this->options['payment_method_types'];
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

		$pi = $payment->getProviderId();
		if (str_starts_with($pi, 'cs_')) {
			//	The payment intent is a checkout session
			try {
				$session = Session::retrieve($pi);
			} catch (Throwable $e) {
				throw new GatewayException($e->getMessage());
			}
			$pi = $session->payment_intent;
			if (empty($pi)) {
				throw new GatewayException("Payment intent not found");
			}
			$payment->setProviderId($pi);
		}

		try {
			$paymentIntent = PaymentIntent::retrieve($pi);
		} catch (Throwable $e) {
			throw new GatewayException($e->getMessage());
		}

		$payment->setStatus($this->mapStatus($paymentIntent->status));
		return $payment->getStatus();
	}

	/**
	 * @throws GatewayException
	 */
	public function refund(Payment $payment, ?float $amount = null): void {
		$isFullRefund = is_null($amount);
		Stripe::setApiKey($this->credentials['secret_key']);
		$options = [
			'payment_intent' => $payment->getProviderId(),
		];
		if (!$isFullRefund) {
			$options['amount'] = $amount * 100;
		}
		try {
			Refund::create($options);
			$payment->setStatus($isFullRefund ? Status::Refunded : Status::PartiallyRefunded);
		} catch (Throwable $e) {
			throw new GatewayException($e->getMessage());
		}
	}

	/**
	 * @throws GatewayException
	 */
	public function getStatusFromWebhook(Payment $payment, RequestInterface|null $request = null): Status {
		Stripe::setApiKey($this->credentials['secret_key']);
		try {
			// Verify the webhook signature
			$event = Webhook::constructEvent(
				$request->getBody()->getContents(),
				$_SERVER['HTTP_STRIPE_SIGNATURE'],
				$this->credentials['webhook_secret']
			);

			// Check if the event is related to a Payment Intent
			if ($event->type === 'payment_intent.succeeded' ||
				$event->type === 'payment_intent.payment_failed' ||
				$event->type === 'payment_intent.processing' ||
				$event->type === 'payment_intent.canceled') {

				$paymentIntent = $event->data->object;

				$status = $paymentIntent->status;

				return $this->mapStatus(str_replace('payment_intent.', '', $status));
			}

			if ($event->type === 'charge.dispute.created') {
				return Status::Disputed;
			}

			if ($event->type === 'refund.created') {
				if ($event->data->amount === (int)$payment->getAmount() * 100) {
					return Status::Refunded;
				}
				return Status::PartiallyRefunded;
			}

			// If the event is not related to a Payment Intent, do nothing
			return $payment->getStatus();

		} catch (SignatureVerificationException|Exception $e) {
			throw new GatewayException($e->getMessage());
		}
	}

	private function mapStatus(string $status): Status {
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
			default:
				return Status::Failed;
		}
	}
}