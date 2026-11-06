<?php

namespace MasterPuffin\PaymentGateways\Tests;

use MasterPuffin\PaymentGateways\Exceptions\GatewayException;
use MasterPuffin\PaymentGateways\Status;
use PHPUnit\Framework\Attributes\Group;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\Dispute;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;

/**
 * The Stripe provider against Stripe's test mode, see {@see NetworkTestCase} for credentials and limits.
 */
#[Group('network')]
class StripeTest extends NetworkTestCase {
	/**
	 * Creates a Checkout session and settles it before anybody paid.
	 * Why: Callers settle every open session at night. An open session must come back as Open with its session id, an
	 * exception would stop the nightly run and a guessed payment intent would lose the payment.
	 */
	public function testAnOpenSessionIsOpen(): void {
		$gateway = $this->stripe();
		$payment = $this->payment();

		$url = $gateway->create($payment);

		$this->assertStringStartsWith('https://checkout.stripe.com/', $url);
		$this->assertStringStartsWith('cs_test_', $payment->getProviderId());
		$session = $payment->getProviderId();
		$this->assertSame(Status::Open, $gateway->execute($payment));
		$this->assertSame($session, $payment->getProviderId());
		$gateway->cancel($payment);
	}

	/**
	 * Expires a session, settles it, expires it again and parses the real `checkout.session.expired` event.
	 * Why: An aborted booking is only deletable once its session can no longer be paid, and a retry must not fail. The
	 * event must name the session, the only id such a payment is stored with.
	 */
	public function testAnExpiredSessionIsCancelled(): void {
		$gateway = $this->stripe();
		$payment = $this->payment();
		$gateway->create($payment);
		$session = $payment->getProviderId();

		$gateway->cancel($payment);

		$this->assertSame(Status::Cancelled, $payment->getStatus());
		$this->assertSame(Status::Cancelled, $gateway->execute($payment));
		$gateway->cancel($payment);
		$this->assertSame(Status::Cancelled, $payment->getStatus());
		$event = $this->stripeEvent('checkout.session.expired', fn(object $object) => $object->id === $session);
		$this->assertSame([$session], $gateway->getProviderIdsFromWebhook($event));
		$this->assertSame(Status::Cancelled, $gateway->parseWebhook($payment, $event)->status);
	}

	/**
	 * Creates a session in a currency without minor unit and reads the amount Stripe stored.
	 * Why: Stripe would take 1234 JPY sent as 123400 as a hundred times the price.
	 */
	public function testZeroDecimalAmountsAreSentAsTheyAre(): void {
		$gateway = $this->stripe();
		$payment = $this->payment(1234.0, 'JPY');
		$gateway->create($payment);

		$session = Session::retrieve($payment->getProviderId());

		$this->assertSame(1234, $session->amount_total);
		$this->assertSame('jpy', $session->currency);
		$gateway->cancel($payment);
	}

	/**
	 * Pays with a test card, settles the payment and parses the real `payment_intent.succeeded` event.
	 * Why: The real answer of a paid payment decides whether it counts as paid and which amount is booked.
	 */
	public function testAPaidPaymentSucceedsWithItsAmount(): void {
		$gateway = $this->stripe();
		$payment = $this->payment();
		$this->payWithCard($payment);

		$this->assertSame(Status::Succeeded, $gateway->execute($payment));
		$this->assertSame(12.34, $payment->getAmount());
		$event = $this->stripeEvent('payment_intent.succeeded', self::ofIntent($payment->getProviderId()));
		$this->assertSame([$payment->getProviderId()], $gateway->getProviderIdsFromWebhook($event));
		$this->assertSame(Status::Succeeded, $gateway->parseWebhook($payment, $event)->status);
	}

	/**
	 * Pays with manual capture, so the money is only reserved, and settles the payment.
	 * Why: `execute()` must capture a reserved payment, otherwise it expires after 7 days and the money never arrives.
	 */
	public function testAnAuthorizedPaymentIsCaptured(): void {
		$gateway = $this->stripe();
		$payment = $this->payment();
		$this->payWithCard($payment, captureMethod: 'manual');
		$this->assertSame('requires_capture', PaymentIntent::retrieve($payment->getProviderId())->status);

		$this->assertSame(Status::Succeeded, $gateway->execute($payment));

		$this->assertSame('succeeded', PaymentIntent::retrieve($payment->getProviderId())->status);
	}

	/**
	 * Pays 1234 JPY and settles the payment.
	 * Why: The amount comes back in yen; dividing it by 100 would book 12.34 yen.
	 */
	public function testAZeroDecimalPaymentKeepsItsAmount(): void {
		$gateway = $this->stripe();
		$payment = $this->payment(1234.0, 'JPY');
		$this->payWithCard($payment);

		$gateway->execute($payment);

		$this->assertSame(1234.0, $payment->getAmount());
	}

	/**
	 * Reserves a payment, cancels the payment intent and parses the real `payment_intent.canceled` event.
	 * Why: A cancelled payment will never bring money, it must count as failed.
	 */
	public function testACancelledPaymentFails(): void {
		$gateway = $this->stripe();
		$payment = $this->payment();
		$this->payWithCard($payment, captureMethod: 'manual');
		PaymentIntent::retrieve($payment->getProviderId())->cancel();

		$event = $this->stripeEvent('payment_intent.canceled', self::ofIntent($payment->getProviderId()));

		$this->assertSame(Status::Failed, $gateway->parseWebhook($payment, $event)->status);
	}

	/**
	 * Pays with the test card that is always declined and parses the real `payment_intent.payment_failed` event.
	 * Why: After a declined card Stripe keeps the payment intent open for another payment method, so it is Pending and not
	 * Failed: the customer may still pay it.
	 */
	public function testADeclinedCardLeavesThePaymentPending(): void {
		$gateway = $this->stripe();
		$payment = $this->payment();
		try {
			$this->payWithCard($payment, 'pm_card_chargeDeclined');
			$this->fail('The test card must be declined');
		} catch (CardException $e) {
			$payment->setProviderId($e->getError()->payment_intent->id);
		}

		$event = $this->stripeEvent('payment_intent.payment_failed', self::ofIntent($payment->getProviderId()));

		$this->assertSame('requires_payment_method', PaymentIntent::retrieve($payment->getProviderId())->status);
		$this->assertSame(Status::Pending, $gateway->parseWebhook($payment, $event)->status);
	}

	/**
	 * Refunds 4.00, then the rest, and parses the real `charge.refunded` events.
	 * Why: This sends real money. The status must tell partial from full, the event must report the sum of all refunds.
	 */
	public function testRefundsAreReportedWithTheirTotal(): void {
		$gateway = $this->stripe();
		$payment = $this->payment();
		$this->payWithCard($payment);
		$gateway->execute($payment);

		$gateway->refund($payment, 4.0);
		$this->assertSame(Status::PartiallyRefunded, $payment->getStatus());
		$partial = $this->stripeEvent('charge.refunded', self::ofIntent($payment->getProviderId(), fn(object $charge) => $charge->amount_refunded === 400));
		$this->assertSame([$payment->getProviderId()], $gateway->getProviderIdsFromWebhook($partial));
		$this->assertSame(Status::PartiallyRefunded, $gateway->parseWebhook($payment, $partial)->status);
		$this->assertSame(4.0, $gateway->parseWebhook($payment, $partial)->refundedTotal);

		$gateway->refund($payment, 8.34);
		$this->assertSame(Status::Refunded, $payment->getStatus());
		$full = $gateway->parseWebhook($payment, $this->stripeEvent('charge.refunded', self::ofIntent($payment->getProviderId(), fn(object $charge) => $charge->refunded)));
		$this->assertSame(Status::Refunded, $full->status);
		$this->assertSame(12.34, $full->refundedTotal);
	}

	/**
	 * Refunds 400 of 1234 JPY and parses the real `charge.refunded` event.
	 * Why: The refunded total comes in yen as well; dividing it by 100 would report 4 yen.
	 */
	public function testAZeroDecimalRefundKeepsItsAmount(): void {
		$gateway = $this->stripe();
		$payment = $this->payment(1234.0, 'JPY');
		$this->payWithCard($payment);
		$gateway->execute($payment);

		$gateway->refund($payment, 400.0);

		$event = $gateway->parseWebhook($payment, $this->stripeEvent('charge.refunded', self::ofIntent($payment->getProviderId())));
		$this->assertSame(400.0, $event->refundedTotal);
	}

	/**
	 * Refunds more than was paid.
	 * Why: Stripe rejects it. The caller must get an error and must not book a refund.
	 */
	public function testARefundAboveThePaymentFails(): void {
		$gateway = $this->stripe();
		$payment = $this->payment();
		$this->payWithCard($payment);
		$gateway->execute($payment);

		$this->expectException(GatewayException::class);

		$gateway->refund($payment, 20.0);
	}

	/**
	 * Delivers a real event signed with another secret.
	 * Why: Without this anybody could mark payments as paid with a single request.
	 */
	public function testAForgedEventIsRejected(): void {
		$gateway = $this->stripe();
		$payment = $this->payment();
		$this->payWithCard($payment);

		$this->expectException(GatewayException::class);

		$gateway->parseWebhook($payment, $this->stripeEvent('payment_intent.succeeded', self::ofIntent($payment->getProviderId()), 'whsec_wrong'));
	}

	/**
	 * Parses the real event of something that is no payment (a new customer).
	 * Why: Events the provider doesn't handle must change nothing.
	 */
	public function testOtherEventsKeepTheStatus(): void {
		$gateway = $this->stripe();
		$payment = $this->payment();
		$this->payWithCard($payment);
		$payment->setStatus(Status::Pending);
		$customer = Customer::create(['description' => 'PaymentGateways network test']);

		$event = $this->stripeEvent('customer.created', fn(object $object) => $object->id === $customer->id);
		$customer->delete();

		$this->assertSame(Status::Pending, $gateway->parseWebhook($payment, $event)->status);
	}

	/**
	 * Pays with the test card that always gets a chargeback, parses the real `charge.dispute.created`, a repeated real
	 * `payment_intent.succeeded`, wins the dispute with Stripe's winning test evidence and parses `charge.dispute.closed`.
	 * Why: The whole life of a won chargeback with real data: the money goes, its amount is known, a retried old event
	 * doesn't end it, and only the closing brings the money back.
	 */
	public function testAChargebackIsReportedAndCanBeWon(): void {
		$gateway = $this->stripe();
		$payment = $this->payment();
		$this->payWithCard($payment, 'pm_card_createDispute');
		$gateway->execute($payment);
		$dispute = $this->waitForDispute($payment->getProviderId());

		$created = $gateway->parseWebhook($payment, $this->stripeEvent('charge.dispute.created', self::ofIntent($payment->getProviderId())));
		$this->assertSame(Status::Disputed, $created->status);
		$this->assertSame(12.34, $created->disputedAmount);

		$payment->setStatus(Status::Disputed);
		$repeated = $this->stripeEvent('payment_intent.succeeded', self::ofIntent($payment->getProviderId()));
		$this->assertSame(Status::Disputed, $gateway->parseWebhook($payment, $repeated)->status);

		Dispute::update($dispute->id, ['evidence' => ['uncategorized_text' => 'winning_evidence']]);
		$this->waitForDispute($payment->getProviderId(), 'won');
		$closed = $gateway->parseWebhook($payment, $this->stripeEvent('charge.dispute.closed', self::ofIntent($payment->getProviderId())));
		$this->assertSame(Status::Succeeded, $closed->status);
	}

	/**
	 * Loses a chargeback with Stripe's losing test evidence and parses the real `charge.dispute.closed`.
	 * Why: A lost chargeback must stay disputed, the money is gone for good.
	 */
	public function testALostChargebackStaysDisputed(): void {
		$gateway = $this->stripe();
		$payment = $this->payment();
		$this->payWithCard($payment, 'pm_card_createDispute');
		$gateway->execute($payment);
		$dispute = $this->waitForDispute($payment->getProviderId());
		$payment->setStatus(Status::Disputed);

		Dispute::update($dispute->id, ['evidence' => ['uncategorized_text' => 'losing_evidence']]);
		$this->waitForDispute($payment->getProviderId(), 'lost');

		$closed = $gateway->parseWebhook($payment, $this->stripeEvent('charge.dispute.closed', self::ofIntent($payment->getProviderId())));
		$this->assertSame(Status::Disputed, $closed->status);
	}

	/**
	 * Pays by SEPA direct debit with Stripe's test IBAN and parses the real `payment_intent.processing` event. Stripe's
	 * test mode needs minutes until the debit succeeds; the success event is the same as for cards
	 * ({@see self::testAPaidPaymentSucceedsWithItsAmount()}).
	 * Why: A direct debit takes days. Until the money arrives it must be Pending, no income yet.
	 */
	public function testADirectDebitIsPendingUntilTheMoneyArrives(): void {
		$gateway = $this->stripe();
		$payment = $this->payment();
		$intent = PaymentIntent::create([
			'amount' => 1234,
			'currency' => 'eur',
			'allowed_payment_method_types' => ['sepa_debit'],
			'payment_method_data' => ['type' => 'sepa_debit', 'sepa_debit' => ['iban' => 'DE89370400440532013000'],
				'billing_details' => ['name' => 'Network Test', 'email' => 'network-test@example.com']],
			'mandate_data' => ['customer_acceptance' => ['type' => 'online', 'online' => ['ip_address' => '127.0.0.1', 'user_agent' => 'PaymentGateways network test']]],
			'confirm' => true,
		]);
		$payment->setProviderId($intent->id);
		$payment->setStatus(Status::Open);

		$processing = $gateway->parseWebhook($payment, $this->stripeEvent('payment_intent.processing', self::ofIntent($intent->id)));

		$this->assertSame(Status::Pending, $processing->status);
	}

	/**
	 * Pays with the test card for an inquiry (a chargeback warning without money withdrawn), closes it with Stripe's winning
	 * test evidence and parses the real `charge.dispute.closed`.
	 * Why: A closed inquiry (`warning_closed`) leaves the money with the seller, so the payment is Succeeded again.
	 */
	public function testAClosedInquiryKeepsTheMoney(): void {
		$gateway = $this->stripe();
		$payment = $this->payment();
		$this->payWithCard($payment, 'pm_card_createDisputeInquiry');
		$gateway->execute($payment);
		$inquiry = $this->waitForDispute($payment->getProviderId());
		$payment->setStatus(Status::Disputed);

		Dispute::update($inquiry->id, ['evidence' => ['uncategorized_text' => 'winning_evidence']]);
		$closed = $this->waitForDispute($payment->getProviderId(), 'warning_closed');

		$this->assertSame('warning_closed', $closed->status);
		$event = $gateway->parseWebhook($payment, $this->stripeEvent('charge.dispute.closed', self::ofIntent($payment->getProviderId())));
		$this->assertSame(Status::Succeeded, $event->status);
	}
}
