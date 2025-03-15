<?php

namespace Providers;

use Exceptions\GatewayException;
use Payment;
use ProviderInterface;
use Stripe\Checkout\Session;
use Throwable;

class Stripe extends Base implements ProviderInterface {

	/**
	 * @throws GatewayException
	 */
	public function create(Payment $payment): null {
		$this->payment = $payment;
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

		try {
			$session= Session::create([
				'customer_email' => $this->payment->customer->email,
				'payment_method_types' => $this->options['payment_methods'],
				'line_items' => $line_items,
				'mode' => 'payment',
				'success_url' => $this->successUrl,
				'cancel_url' => $this->cancelUrl,
			]);
		} catch (Throwable $e) {
			throw new GatewayException($e->getMessage());
		}
		return $session->url;
	}

	public function execute() {

	}

}