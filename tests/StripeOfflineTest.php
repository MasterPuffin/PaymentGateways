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
	 * A succeeded payment intent with its charge.
	 */
	private function paymentIntent(array $charge = [], string $status = 'succeeded'): array {
		return ['id' => 'pi_123', 'object' => 'payment_intent', 'status' => $status, 'amount' => 10000, 'currency' => 'eur',
			'latest_charge' => $charge + ['id' => 'ch_1', 'object' => 'charge', 'currency' => 'eur', 'amount' => 10000, 'amount_refunded' => 0, 'refunded' => false, 'dispute' => null]];
	}

	private function storedPayment(Status $status = Status::Pending): Payment {
		$payment = new Payment();
		$payment->setProviderId('pi_123');
		$payment->setCurrencyCode('EUR');
		$payment->setAmount(100.0);
		$payment->setStatus($status);
		return $payment;
	}

	/**
	 * Fetches the state of payments whose payment intent is `succeeded`: untouched, refunded completely, refunded in part.
	 * Why: Stripe leaves the payment intent `succeeded` after a refund. Read from the payment intent alone, refunded
	 * payments that were never booked were booked as income without their refunds.
	 */
	public function testFetchingAPaymentReportsItsRefunds(): void {
		$untouched = (new FakeStripe(['pi_123' => $this->paymentIntent()]))->fetchStatus($this->storedPayment());
		$refunded = (new FakeStripe(['pi_123' => $this->paymentIntent(['amount_refunded' => 10000, 'refunded' => true])]))->fetchStatus($this->storedPayment());
		$partially = (new FakeStripe(['pi_123' => $this->paymentIntent(['amount_refunded' => 6000])]))->fetchStatus($this->storedPayment());

		$this->assertSame([Status::Succeeded, null], [$untouched->status, $untouched->refundedTotal]);
		$this->assertSame([Status::Refunded, 100.0], [$refunded->status, $refunded->refundedTotal]);
		$this->assertSame([Status::PartiallyRefunded, 60.0], [$partially->status, $partially->refundedTotal]);
	}

	/**
	 * Fetches a payment that is still processing (SEPA) and settles one that was refunded meanwhile with `execute()`.
	 * Why: Pending payments are checked without webhooks; a processing payment must stay pending. `execute()` settles
	 * old Checkout sessions and must not report a refunded payment as succeeded either.
	 */
	public function testAProcessingPaymentStaysPendingAndExecuteSeesRefunds(): void {
		$processing = (new FakeStripe(['pi_123' => $this->paymentIntent(status: 'processing')]))->fetchStatus($this->storedPayment());
		$payment = $this->storedPayment(Status::Open);
		$executed = (new FakeStripe(['pi_123' => $this->paymentIntent(['amount_refunded' => 10000, 'refunded' => true])]))->execute($payment);

		$this->assertSame(Status::Pending, $processing->status);
		$this->assertSame([Status::Refunded, Status::Refunded], [$executed, $payment->getStatus()]);
	}

	/**
	 * Fetches payments with an open chargeback, an inquiry, a won dispute of a disputed payment and a closed inquiry of
	 * an undisputed one.
	 * Why: Same meaning as the dispute webhooks: a chargeback withdraws the money, an inquiry doesn't, and only a dispute
	 * decided for the seller ends a chargeback. A closed inquiry moved no money and must not be booked as dispute.
	 */
	public function testFetchingAPaymentReportsItsDispute(): void {
		$dispute = fn(string $status) => ['dispute' => ['id' => 'dp_1', 'object' => 'dispute', 'amount' => 4000, 'currency' => 'eur', 'status' => $status]];

		$open = (new FakeStripe(['pi_123' => $this->paymentIntent($dispute('needs_response'))]))->fetchStatus($this->storedPayment(Status::Succeeded));
		$inquiry = (new FakeStripe(['pi_123' => $this->paymentIntent($dispute('warning_needs_response'))]))->fetchStatus($this->storedPayment(Status::Succeeded));
		$won = (new FakeStripe(['pi_123' => $this->paymentIntent($dispute('won'))]))->fetchStatus($this->storedPayment(Status::Disputed));
		$closedInquiry = (new FakeStripe(['pi_123' => $this->paymentIntent($dispute('warning_closed'))]))->fetchStatus($this->storedPayment(Status::Succeeded));

		$this->assertSame([Status::Disputed, 40.0, 'dp_1'], [$open->status, $open->disputedAmount, $open->disputeId]);
		$this->assertSame([Status::Succeeded, false], [$inquiry->status, $inquiry->disputeWon]);
		$this->assertSame([Status::Succeeded, true, 40.0, 'dp_1'], [$won->status, $won->disputeWon, $won->disputedAmount, $won->disputeId]);
		$this->assertSame([Status::Succeeded, false], [$closedInquiry->status, $closedInquiry->disputeWon]);
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
