<?php

namespace MasterPuffin\PaymentGateways\Tests;

use MasterPuffin\PaymentGateways\Payment;
use MasterPuffin\PaymentGateways\PaymentGateway;
use MasterPuffin\PaymentGateways\Provider;
use MasterPuffin\PaymentGateways\Status;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * What Stripe can't produce without a buyer: a completed Checkout session needs somebody on the Checkout page, a closed
 * dispute needs the dispute process. Everything else is tested against the test API in {@see StripeTest}. The event is built and signed here and verified by the real
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

	/**
	 * Parses `charge.dispute.closed` once won and once lost, and a repeated `payment_intent.succeeded`, for a disputed
	 * payment.
	 * Why: Only the won closing may end the chargeback in the caller's ledger, so only it carries `disputeWon`. A
	 * Succeeded without it is no decision about the dispute.
	 */
	public function testOnlyAWonDisputeIsReportedAsWon(): void {
		$gateway = new PaymentGateway(Provider::Stripe_Checkout, ['secret_key' => 'sk_test', 'webhook_secret' => self::SECRET]);
		$payment = new Payment();
		$payment->setProviderId('pi_123');
		$payment->setCurrencyCode('EUR');
		$payment->setStatus(Status::Disputed);
		$dispute = fn(string $status) => ['id' => 'dp_1', 'object' => 'dispute', 'payment_intent' => 'pi_123', 'status' => $status];

		$won = $gateway->parseWebhook($payment, $this->event('charge.dispute.closed', $dispute('won')));
		$lost = $gateway->parseWebhook($payment, $this->event('charge.dispute.closed', $dispute('lost')));
		$repeated = $gateway->parseWebhook($payment, $this->event('payment_intent.succeeded', ['id' => 'pi_123', 'object' => 'payment_intent', 'status' => 'succeeded']));

		$this->assertSame([Status::Succeeded, true], [$won->status, $won->disputeWon]);
		$this->assertSame([Status::Disputed, false], [$lost->status, $lost->disputeWon]);
		$this->assertSame([Status::Disputed, false], [$repeated->status, $repeated->disputeWon]);
	}

	/**
	 * Parses the events of an inquiry: opened (`warning_needs_response`), escalated to a chargeback (`updated` to
	 * `needs_response`), and an inquiry that closed without chargeback (`warning_closed`).
	 * Why: An inquiry withdraws no funds. Reported as Disputed, the caller's ledger would show the money as gone for up
	 * to 120 days although it never left. Its escalation comes as an update of the same dispute, not as a new one.
	 */
	public function testAnInquiryIsNoChargebackUntilItIsEscalated(): void {
		$gateway = new PaymentGateway(Provider::Stripe_Checkout, ['secret_key' => 'sk_test', 'webhook_secret' => self::SECRET]);
		$payment = new Payment();
		$payment->setProviderId('pi_123');
		$payment->setCurrencyCode('EUR');
		$payment->setStatus(Status::Succeeded);
		$dispute = fn(string $status) => ['id' => 'dp_1', 'object' => 'dispute', 'payment_intent' => 'pi_123', 'amount' => 1000, 'currency' => 'eur', 'status' => $status];

		$inquiry = $gateway->parseWebhook($payment, $this->event('charge.dispute.created', $dispute('warning_needs_response')));
		$escalated = $gateway->parseWebhook($payment, $this->event('charge.dispute.updated', $dispute('needs_response')));
		$closed = $gateway->parseWebhook($payment, $this->event('charge.dispute.closed', $dispute('warning_closed')));

		$this->assertSame([Status::Succeeded, null, 'dp_1'], [$inquiry->status, $inquiry->disputedAmount, $inquiry->disputeId]);
		$this->assertSame([Status::Disputed, 10.0, 'dp_1'], [$escalated->status, $escalated->disputedAmount, $escalated->disputeId]);
		$this->assertSame([Status::Succeeded, true, null], [$closed->status, $closed->disputeWon, $closed->disputedAmount]);
	}

	/**
	 * Parses a won and a lost `charge.dispute.closed` with their amount.
	 * Why: Events arrive in any order. Without the amount a caller could not book a dispute whose opening arrived late
	 * or never: a lost one would leave the money in the ledger, a won one could not be told from an inquiry.
	 */
	public function testAClosedDisputeCarriesItsIdAndAmount(): void {
		$gateway = new PaymentGateway(Provider::Stripe_Checkout, ['secret_key' => 'sk_test', 'webhook_secret' => self::SECRET]);
		$payment = new Payment();
		$payment->setProviderId('pi_123');
		$payment->setCurrencyCode('EUR');
		$payment->setStatus(Status::Succeeded);
		$dispute = fn(string $status) => ['id' => 'dp_1', 'object' => 'dispute', 'payment_intent' => 'pi_123', 'amount' => 500, 'currency' => 'eur', 'status' => $status];

		$won = $gateway->parseWebhook($payment, $this->event('charge.dispute.closed', $dispute('won')));
		$lost = $gateway->parseWebhook($payment, $this->event('charge.dispute.closed', $dispute('lost')));

		$this->assertSame([Status::Succeeded, true, 5.0, 'dp_1'], [$won->status, $won->disputeWon, $won->disputedAmount, $won->disputeId]);
		$this->assertSame([Status::Disputed, false, 5.0, 'dp_1'], [$lost->status, $lost->disputeWon, $lost->disputedAmount, $lost->disputeId]);
	}

	/**
	 * A signed event as Stripe sends it.
	 */
	private function event(string $type, array $object): RequestInterface {
		$body = json_encode(['id' => 'evt_' . bin2hex(random_bytes(4)), 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]]);
		$time = time();
		$factory = new Psr17Factory();
		return $factory->createRequest('POST', 'https://example.com/webhook')
			->withHeader('Stripe-Signature', "t=$time,v1=" . hash_hmac('sha256', "$time.$body", self::SECRET))
			->withBody($factory->createStream($body));
	}
}
