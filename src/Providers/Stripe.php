<?php

namespace Providers;

use Exceptions\GatewayException;
use Payment;
use ProviderInterface;
use Status;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Throwable;

class Stripe extends Base implements ProviderInterface {

	/**
	 * @throws GatewayException
	 */
	public function create(Payment $payment): null {
		\Stripe\Stripe::setApiKey($this->credentials['secret_key']);

		$line_items = [
			'price_data' => [
				'currency' => strtolower($payment->currencyCode),
				'product_data' => [
					'name' => $payment->description,
				],
				'unit_amount' => (int)($payment->amount * 100),
			],
			'quantity' => 1
		];
		$options = [
			'customer_email' => $payment->customer->email,
			'line_items' => $line_items,
			'mode' => 'payment',
			'success_url' => $this->successUrl,
			'cancel_url' => $this->cancelUrl,
			'metadata' => $payment->metadata,
		];
		if (!empty(($this->options['payment_method_types']))) {
			$options['payment_method_types'] = $this->options['payment_method_types'];
		}

		try {
			$session = Session::create($options);
		} catch (Throwable $e) {
			throw new GatewayException($e->getMessage());
		}
		return $session->url;
	}

	/**
	 * @throws GatewayException
	 */
	public function execute(Payment $payment): Status {
		\Stripe\Stripe::setApiKey($this->credentials['secret_key']);

		try {
			$paymentIntent = PaymentIntent::retrieve($payment->providerId);
		} catch (Throwable $e) {
			throw new GatewayException($e->getMessage());
		}

		switch ($paymentIntent->status) {
			case 'canceled':
				$payment->status = Status::Failed;
				break;
			case 'requires_action':
			case 'requires_payment_method':
			case 'requires_confirmation':
			case 'processing':
				$payment->status = Status::Pending;
				break;
			case 'succeeded':
				$payment->status = Status::Succeeded;
				break;
			default:
				$payment->status = Status::Failed;
		}
		return $payment->status;
	}

	/**
	 * @throws GatewayException
	 */
	public function refund(Payment $payment, ?float $amount = null): void {
		\Stripe\Stripe::setApiKey($this->credentials['secret_key']);
		$options = [
			'charge' => $payment->providerId,
		];
		if (!is_null($amount)) {
			$options['amount'] = $amount * 100;
		}
		try {
			Refund::create($options);
		} catch (Throwable $e) {
			throw new GatewayException($e->getMessage());
		}
	}

}