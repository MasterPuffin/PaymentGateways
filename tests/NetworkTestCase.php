<?php

namespace MasterPuffin\PaymentGateways\Tests;

use MasterPuffin\PaymentGateways\Payment;
use MasterPuffin\PaymentGateways\PaymentGateway;
use MasterPuffin\PaymentGateways\Provider;
use Nyholm\Psr7\Factory\Psr17Factory;
use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\Models\Builders\AmountWithBreakdownBuilder;
use PaypalServerSdkLib\Models\Builders\CardRequestBuilder;
use PaypalServerSdkLib\Models\Builders\OrderRequestBuilder;
use PaypalServerSdkLib\Models\Builders\PaymentSourceBuilder;
use PaypalServerSdkLib\Models\Builders\PurchaseUnitRequestBuilder;
use PaypalServerSdkLib\Models\CheckoutPaymentIntent;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Stripe\Dispute;
use Stripe\Event;
use Stripe\Stripe;

/**
 * Base of the tests against the real test APIs of Stripe and PayPal. Every provider behaviour is tested here; only what the
 * providers can't produce on request is tested offline ({@see StripeOfflineTest}, {@see PayPalOfflineTest}).
 *
 * Needs credentials in the environment, tests are skipped without them:
 *  - `STRIPE_TEST_SECRET_KEY` (must be a test key, `sk_test_…`),
 *  - `PAYPAL_SANDBOX_CLIENT_ID` and `PAYPAL_SANDBOX_SECRET` (card processing enabled for the sandbox app).
 * Only test objects are created. Payments are made through the API with test cards: the Stripe Checkout page and the
 * PayPal approval page need a buyer in the browser. Stripe webhooks are the real events from the Events API, PayPal
 * webhooks the payloads of PayPal's webhook simulator; both are delivered here, there is no public endpoint.
 */
abstract class NetworkTestCase extends TestCase {
	protected const string WEBHOOK_SECRET = 'whsec_network_test';

	protected function stripe(): PaymentGateway {
		$key = (string)getenv('STRIPE_TEST_SECRET_KEY');
		if (!str_starts_with($key, 'sk_test_')) {
			$this->markTestSkipped('STRIPE_TEST_SECRET_KEY with a test key is not set');
		}
		Stripe::setApiKey($key);
		return $this->configure(new PaymentGateway(Provider::Stripe_Checkout, ['secret_key' => $key, 'webhook_secret' => self::WEBHOOK_SECRET], ['payment_method_types' => ['card']]));
	}

	protected function paypal(): PaymentGateway {
		[$id, $secret] = $this->paypalCredentials();
		return $this->configure(new PaymentGateway(Provider::PayPal_REST, ['client_id' => $id, 'client_secret' => $secret]));
	}

	private function configure(PaymentGateway $gateway): PaymentGateway {
		$gateway->setSandbox(true);
		$gateway->setSuccessUrl('https://example.com/success');
		$gateway->setCancelUrl('https://example.com/cancel');
		return $gateway;
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	private function paypalCredentials(): array {
		$id = (string)getenv('PAYPAL_SANDBOX_CLIENT_ID');
		$secret = (string)getenv('PAYPAL_SANDBOX_SECRET');
		if ($id === '' || $secret === '') {
			$this->markTestSkipped('PAYPAL_SANDBOX_CLIENT_ID and PAYPAL_SANDBOX_SECRET are not set');
		}
		return [$id, $secret];
	}

	protected function payment(float $amount = 12.34, string $currency = 'EUR'): Payment {
		$payment = new Payment();
		$payment->setAmount($amount);
		$payment->setCurrencyCode($currency);
		$payment->setDescription('PaymentGateways network test');
		$payment->getCustomer()->setEmail('network-test@example.com');
		$payment->setMetadata(['test' => 'network']);
		return $payment;
	}

	protected function request(string $body, array $headers = []): RequestInterface {
		$factory = new Psr17Factory();
		$request = $factory->createRequest('POST', 'https://example.com/webhook')->withBody($factory->createStream($body));
		foreach ($headers as $name => $value) {
			$request = $request->withHeader($name, $value);
		}
		return $request;
	}

	// ---- Stripe ------------------------------------------------------------------------------

	/**
	 * Pays a payment intent with a Stripe test card, like the Checkout page does after the buyer confirmed.
	 *
	 * @param string $card Test payment method, e.g. `pm_card_visa`, `pm_card_createDispute`
	 */
	protected function payWithCard(Payment $payment, string $card = 'pm_card_visa', string $captureMethod = 'automatic'): void {
		$intent = \Stripe\PaymentIntent::create([
			'amount' => $payment->getCurrencyCode() === 'JPY' ? (int)$payment->getAmount() : (int)round($payment->getAmount() * 100),
			'currency' => strtolower($payment->getCurrencyCode()),
			'payment_method' => $card,
			'allowed_payment_method_types' => ['card'],
			'capture_method' => $captureMethod,
			'confirm' => true,
		]);
		$payment->setProviderId($intent->id);
	}

	/**
	 * The latest real event of this type whose object matches, as a webhook request signed with `$secret`. Stripe creates
	 * events asynchronously, so this waits up to a minute.
	 *
	 * @param callable(object): bool $matches
	 */
	protected function stripeEvent(string $type, callable $matches, string $secret = self::WEBHOOK_SECRET): RequestInterface {
		$until = time() + 60;
		do {
			foreach (Event::all(['type' => $type, 'limit' => 20])->data as $event) {
				if ($matches($event->data->object)) {
					$body = json_encode($event->toArray());
					$time = time();
					return $this->request($body, ['Stripe-Signature' => "t=$time,v1=" . hash_hmac('sha256', "$time.$body", $secret)]);
				}
			}
			sleep(2);
		} while (time() < $until);
		$this->fail("No $type event within 60 seconds");
	}

	/**
	 * Matches the objects of an event that belong to the payment intent: the intent itself, its charges and disputes.
	 */
	protected static function ofIntent(string $paymentIntent, ?callable $and = null): callable {
		return fn(object $object) => ($object->object === 'payment_intent' ? $object->id : ($object->payment_intent ?? null)) === $paymentIntent
			&& (is_null($and) || $and($object));
	}

	/**
	 * Waits until the dispute of the payment intent exists (and has the status), Stripe processes test disputes
	 * asynchronously.
	 */
	protected function waitForDispute(string $paymentIntent, ?string $status = null): Dispute {
		$until = time() + 60;
		do {
			$dispute = Dispute::all(['payment_intent' => $paymentIntent, 'limit' => 1])->data[0] ?? null;
			if (!is_null($dispute) && (is_null($status) || $dispute->status === $status)) {
				return $dispute;
			}
			sleep(2);
		} while (time() < $until);
		$this->fail('No dispute' . ($status ? " with status $status" : '') . " for $paymentIntent within 60 seconds");
	}

	// ---- PayPal ------------------------------------------------------------------------------

	protected function paypalClient(): PaypalServerSdkClient {
		[$id, $secret] = $this->paypalCredentials();
		return PaypalServerSdkClientBuilder::init()
			->clientCredentialsAuthCredentials(ClientCredentialsAuthCredentialsBuilder::init($id, $secret))
			->environment(Environment::SANDBOX)->timeout(30)->build();
	}

	/**
	 * Pays a PayPal order with the sandbox Visa test card through the SDK: captured in one step (intent CAPTURE, returns
	 * the capture id) or only authorized (returns the authorization id). Skips if the sandbox app doesn't allow cards.
	 */
	protected function payPayPalWithCard(Payment $payment, bool $captureNow = true): string {
		$order = OrderRequestBuilder::init($captureNow ? CheckoutPaymentIntent::CAPTURE : CheckoutPaymentIntent::AUTHORIZE, [
			PurchaseUnitRequestBuilder::init(
				AmountWithBreakdownBuilder::init($payment->getCurrencyCode(), number_format($payment->getAmount(), 2, '.', ''))->build()
			)->build(),
		])->paymentSource(PaymentSourceBuilder::init()->card(
			CardRequestBuilder::init()->number('4012888888881881')->expiry('2030-12')->securityCode('123')->name('Network Test')->build()
		)->build())->build();

		$response = $this->paypalClient()->getOrdersController()->createOrder(['body' => $order, 'prefer' => 'return=representation', 'paypalRequestId' => uniqid('network', true)]);
		if ($response->isError()) {
			$this->markTestSkipped('PayPal sandbox card payment not possible: ' . substr(json_encode($response->getBody()), 0, 200));
		}
		$payments = $response->getResult()->getPurchaseUnits()[0]->getPayments();
		$id = $captureNow ? ($payments?->getCaptures()[0] ?? null)?->getId() : ($payments?->getAuthorizations()[0] ?? null)?->getId();
		$this->assertNotEmpty($id, $captureNow ? 'The order was not captured' : 'The order was not authorized');
		return $id;
	}

	/**
	 * A webhook as PayPal sends it, from PayPal's webhook simulator (real payload format with sample ids). PayPal doesn't
	 * sign it in a way the provider checks, so it is delivered as is.
	 *
	 * @param callable(object): void|null $change Adjusts the decoded event, e.g. to point it to a real capture
	 */
	protected function paypalEvent(string $type, ?callable $change = null): RequestInterface {
		$client = $this->paypalClient();
		$token = $client->getClientCredentialsAuth()->fetchToken()->getAccessToken();
		$curl = curl_init($client->getBaseUri() . '/v1/notifications/simulate-event');
		curl_setopt_array($curl, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST => true,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
			CURLOPT_POSTFIELDS => json_encode(['url' => 'https://example.com/webhook', 'event_type' => $type,
				'resource_version' => str_starts_with($type, 'CUSTOMER.DISPUTE.') ? '1.0' : '2.0']),
		]);
		$event = json_decode((string)curl_exec($curl));
		$this->assertSame($type, $event->event_type ?? null, 'The webhook simulator returned no event');
		if (!is_null($change)) {
			$change($event);
		}
		return $this->request(json_encode($event));
	}
}
