<?php

namespace MasterPuffin\PaymentGateways\Tests;

use MasterPuffin\PaymentGateways\Payment;
use MasterPuffin\PaymentGateways\PaymentGateway;
use MasterPuffin\PaymentGateways\Provider;
use MasterPuffin\PaymentGateways\Status;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

/**
 * What Stripe can't produce without a buyer: a completed Checkout session needs somebody on the Checkout page. Everything
 * else is tested against the test API in {@see StripeTest}. The event is built and signed here and verified by the real
 * gateway, no request goes to Stripe.
 */
class StripeOfflineTest extends TestCase {
	private const string SECRET = 'whsec_test';

	/**
	 * Parses a `checkout.session.completed` and reads its ids.
	 * Why: The event does not mean paid, authorized payments still have to be captured by `execute()`. A payment is stored
	 * with its session id until then, so the session must be named first.
	 */
	public function testACompletedCheckoutIsToBeSettled(): void {
		$gateway = new PaymentGateway(Provider::Stripe_Checkout, ['secret_key' => 'sk_test', 'webhook_secret' => self::SECRET]);
		$payment = new Payment();
		$payment->setProviderId('cs_1');
		$payment->setCurrencyCode('EUR');
		$payment->setStatus(Status::Open);
		$body = json_encode(['id' => 'evt_1', 'object' => 'event', 'type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_1', 'object' => 'checkout.session', 'payment_intent' => 'pi_123']]]);
		$time = time();
		$factory = new Psr17Factory();
		$request = $factory->createRequest('POST', 'https://example.com/webhook')
			->withHeader('Stripe-Signature', "t=$time,v1=" . hash_hmac('sha256', "$time.$body", self::SECRET))
			->withBody($factory->createStream($body));

		$event = $gateway->parseWebhook($payment, $request);

		$this->assertTrue($event->checkoutCompleted);
		$this->assertSame(Status::Open, $event->status);
		$this->assertSame(['cs_1', 'pi_123'], $gateway->getProviderIdsFromWebhook($request));
	}
}
