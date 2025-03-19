<?php

namespace MasterPuffin\PaymentGateways\Providers;

use MasterPuffin\PaymentGateways\Exceptions\GatewayException;
use MasterPuffin\PaymentGateways\Payment;
use MasterPuffin\PaymentGateways\ProviderInterface;
use MasterPuffin\PaymentGateways\Status;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\Webhook;
use Throwable;

class Stripe_Checkout extends Base implements ProviderInterface {

	/**
	 * @throws GatewayException
	 */
	public function create(Payment $payment): string {
		\Stripe\Stripe::setApiKey($this->credentials['secret_key']);

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
		\Stripe\Stripe::setApiKey($this->credentials['secret_key']);

		try {
			$paymentIntent = PaymentIntent::retrieve($payment->getProviderId());
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
		\Stripe\Stripe::setApiKey($this->credentials['secret_key']);
		$options = [
			'charge' => $payment->getProviderId(),
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
	public function getStatusFromWebhook(Payment $payment, string $payload = ''): Status {
		\Stripe\Stripe::setApiKey($this->credentials['secret_key']);
		try {
			// Verify the webhook signature
			$event = Webhook::constructEvent(
				$payload,
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

			// If the event is not related to a Payment Intent, do nothing
			return $payment->getStatus();

		} catch (SignatureVerificationException|\Exception $e) {
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