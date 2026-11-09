<?php

namespace MasterPuffin\PaymentGateways\Tests;

use MasterPuffin\PaymentGateways\Exceptions\GatewayException;
use MasterPuffin\PaymentGateways\Providers\Stripe_Checkout;
use Stripe\PaymentIntent;

/**
 * The real Stripe provider without Stripe: only the request for the payment intent is replaced, so the mapping of
 * charge, refunds and dispute runs as in production.
 */
final class FakeStripe extends Stripe_Checkout {
	/**
	 * @param array<string, array> $paymentIntents Payment intents (with expanded `latest_charge.dispute`) by id
	 */
	public function __construct(private readonly array $paymentIntents) {
		$this->setCredentials(['secret_key' => 'sk_test', 'webhook_secret' => 'whsec_test']);
	}

	protected function _retrievePaymentIntent(string $id): PaymentIntent {
		return PaymentIntent::constructFrom($this->paymentIntents[$id] ?? throw new GatewayException('No such payment_intent'));
	}
}
