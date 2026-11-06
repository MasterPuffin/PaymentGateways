<?php

namespace MasterPuffin\PaymentGateways\Tests;

use MasterPuffin\PaymentGateways\Exceptions\GatewayException;
use MasterPuffin\PaymentGateways\Status;
use PaypalServerSdkLib\Models\Builders\MoneyBuilder;
use PaypalServerSdkLib\Models\Builders\RefundRequestBuilder;
use PHPUnit\Framework\Attributes\Group;

/**
 * The PayPal provider against PayPal's sandbox, see {@see NetworkTestCase} for credentials and limits. PayPal doesn't sign
 * the webhooks handled here, so the payload only names what to look at and the status always comes from the API.
 */
#[Group('network')]
class PayPalTest extends NetworkTestCase {
	/**
	 * Creates an order and reads the link for the buyer.
	 * Why: The answer format of PayPal decides whether the customer can pay at all.
	 */
	public function testCreatingAnOrderReturnsTheApprovalLink(): void {
		$payment = $this->payment();

		$url = $this->paypal()->create($payment);

		$this->assertMatchesRegularExpression('#^https://www\.sandbox\.paypal\.com/#', $url);
		$this->assertNotEmpty($payment->getProviderId());
	}

	/**
	 * Executes an order the buyer has not approved.
	 * Why: A customer may come back without paying. That must be an error with PayPal's reason, not a success.
	 */
	public function testAnUnapprovedOrderFailsWithItsReason(): void {
		$gateway = $this->paypal();
		$payment = $this->payment();
		$gateway->create($payment);

		$this->expectException(GatewayException::class);
		$this->expectExceptionMessageMatches('/[A-Z_]{5,}/');

		$gateway->execute($payment);
	}

	/**
	 * Reads the ids of the simulator's capture, refund, reversal, denial and dispute events.
	 * Why: Each event type names the capture in another place; in a capture event the `up` link even points to the
	 * order. A miss means the event finds no payment and is lost.
	 */
	public function testEveryEventTypeNamesItsCapture(): void {
		$gateway = $this->paypal();
		$expected = [
			'PAYMENT.CAPTURE.COMPLETED' => fn(object $e) => $e->resource->id,
			'PAYMENT.CAPTURE.PENDING' => fn(object $e) => $e->resource->id,
			'PAYMENT.CAPTURE.DENIED' => fn(object $e) => $e->resource->id,
			'PAYMENT.CAPTURE.REFUNDED' => fn(object $e) => basename(array_values(array_filter($e->resource->links, fn($l) => $l->rel === 'up'))[0]->href),
			'PAYMENT.CAPTURE.REVERSED' => fn(object $e) => basename(array_values(array_filter($e->resource->links, fn($l) => $l->rel === 'up'))[0]->href),
			'CUSTOMER.DISPUTE.CREATED' => fn(object $e) => $e->resource->disputed_transactions[0]->seller_transaction_id,
		];
		foreach ($expected as $type => $capture) {
			$event = null;
			$request = $this->paypalEvent($type, function (object $e) use (&$event) {
				$event = $e;
			});

			$this->assertSame([$capture($event)], $gateway->getProviderIdsFromWebhook($request), $type);
		}
	}

	/**
	 * Pays with a sandbox card, refunds through the provider and parses simulator capture events pointed to the real capture.
	 * Why: The capture status PayPal really reports decides between paid, partially and fully refunded.
	 */
	public function testACapturedPaymentCanBeRefunded(): void {
		$gateway = $this->paypal();
		$payment = $this->payment();
		$payment->setProviderId($this->payPayPalWithCard($payment));
		$webhook = fn() => $this->paypalEvent('PAYMENT.CAPTURE.COMPLETED', fn(object $e) => $e->resource->id = $payment->getProviderId());

		$this->assertSame(Status::Succeeded, $gateway->parseWebhook($payment, $webhook())->status);

		$gateway->refund($payment, 4.0);
		$this->assertSame(Status::PartiallyRefunded, $payment->getStatus());
		$this->assertSame(Status::PartiallyRefunded, $gateway->parseWebhook($payment, $webhook())->status);

		$gateway->refund($payment, 8.34);
		$this->assertSame(Status::Refunded, $payment->getStatus());
		$this->assertSame(Status::Refunded, $gateway->parseWebhook($payment, $webhook())->status);
	}

	/**
	 * Refunds a real capture fully, then delivers a capture event that claims COMPLETED.
	 * Why: The webhook is not signed. Trusting its payload would let anybody mark a refunded payment as paid again.
	 */
	public function testAForgedPayloadCannotChangeThePayment(): void {
		$gateway = $this->paypal();
		$payment = $this->payment();
		$payment->setProviderId($this->payPayPalWithCard($payment));
		$gateway->refund($payment, null);

		$event = $gateway->parseWebhook($payment, $this->paypalEvent('PAYMENT.CAPTURE.COMPLETED', function (object $e) use ($payment) {
			$e->resource->id = $payment->getProviderId();
			$e->resource->status = 'COMPLETED';
		}));

		$this->assertSame(Status::Refunded, $event->status);
	}

	/**
	 * Refunds a real capture twice and delivers refund events with the real refund ids and a forged amount.
	 * Why: The payload is not trusted, so the sum must come from PayPal's API, also for refunds made in the dashboard.
	 */
	public function testARefundEventReportsTheTotalFromTheApi(): void {
		$gateway = $this->paypal();
		$payment = $this->payment();
		$payment->setProviderId($this->payPayPalWithCard($payment));
		$refund = fn(string $amount) => $this->paypalClient()->getPaymentsController()->refundCapturedPayment([
			'captureId' => $payment->getProviderId(),
			'prefer' => 'return=representation',
			'body' => RefundRequestBuilder::init()->amount(MoneyBuilder::init('EUR', $amount)->build())->build(),
		])->getResult()->getId();
		$event = fn(string $refundId) => $gateway->parseWebhook($payment, $this->paypalEvent('PAYMENT.CAPTURE.REFUNDED', function (object $e) use ($payment, $refundId) {
			$e->resource->id = $refundId;
			$e->resource->amount->value = '999.00';
			foreach ($e->resource->links as $link) {
				if ($link->rel === 'up') {
					$link->href = dirname($link->href) . '/' . $payment->getProviderId();
				}
			}
		}));

		$first = $event($refund('4.00'));
		$this->assertSame(Status::PartiallyRefunded, $first->status);
		$this->assertSame(4.0, $first->refundedTotal);
		$this->assertNull($first->disputedAmount);

		$this->assertSame(5.5, $event($refund('1.50'))->refundedTotal);
	}

	/**
	 * Parses an event type the provider doesn't handle, for a capture PayPal doesn't know.
	 * Why: Unknown events must change nothing, and the unsigned endpoint must not trigger API calls for them; a call for
	 * the unknown capture would fail.
	 */
	public function testUnknownEventsKeepTheStatusWithoutAskingPayPal(): void {
		$payment = $this->payment();
		$payment->setProviderId('0UNKNOWN0CAPTURE0');
		$payment->setStatus(Status::Pending);

		$event = $this->paypal()->parseWebhook($payment, $this->request(json_encode(['event_type' => 'BILLING.PLAN.CREATED', 'resource' => ['id' => '0UNKNOWN0CAPTURE0']])));

		$this->assertSame(Status::Pending, $event->status);
	}

	/**
	 * Parses a capture event for a capture PayPal doesn't know.
	 * Why: The status is always fetched. An unknown capture must fail the webhook, so it is retried, not change the payment.
	 */
	public function testAWebhookForAnUnknownCaptureFails(): void {
		$payment = $this->payment();
		$payment->setProviderId('0UNKNOWN0CAPTURE0');
		$payment->setStatus(Status::Succeeded);

		$this->expectException(GatewayException::class);

		$this->paypal()->parseWebhook($payment, $this->paypalEvent('PAYMENT.CAPTURE.COMPLETED', fn(object $e) => $e->resource->id = '0UNKNOWN0CAPTURE0'));
	}

	/**
	 * Refunds more than was captured, and a capture PayPal doesn't know.
	 * Why: No money moved, so the caller must get an error with PayPal's reason, which staff see in the panel.
	 */
	public function testRejectedRefundsFailWithTheirReason(): void {
		$gateway = $this->paypal();
		$payment = $this->payment();
		$payment->setProviderId($this->payPayPalWithCard($payment));
		try {
			$gateway->refund($payment, 20.0);
			$this->fail('A refund above the capture must fail');
		} catch (GatewayException $e) {
			$this->assertMatchesRegularExpression('/[A-Z_]{5,}/', $e->getMessage());
		}

		$unknown = $this->payment();
		$unknown->setProviderId('0UNKNOWN0CAPTURE0');
		$this->expectException(GatewayException::class);

		$gateway->refund($unknown, 1.0);
	}

	/**
	 * Authorizes a card payment and voids it through the provider.
	 * Why: A cancelled authorization must end as Cancelled and be void at PayPal, otherwise it could still be captured.
	 */
	public function testCancellingVoidsTheAuthorization(): void {
		$payment = $this->payment();
		$payment->setProviderId($this->payPayPalWithCard($payment, false));

		$this->paypal()->cancel($payment);

		$this->assertSame(Status::Cancelled, $payment->getStatus());
		$authorization = $this->paypalClient()->getPaymentsController()->getAuthorizedPayment(['authorizationId' => $payment->getProviderId()])->getResult();
		$this->assertSame('VOIDED', $authorization->getStatus());
	}

	/**
	 * Authorizes a card payment and captures it afterwards, like {@see \MasterPuffin\PaymentGateways\PaymentGateway::execute()}
	 * does. PayPal's sandbox puts such a capture into review (status PENDING, reason PENDING_REVIEW), not always.
	 * Why: A capture in review is not settled money. It must count as pending, a caller would otherwise book income that
	 * PayPal may still decline.
	 */
	public function testACaptureInReviewIsPending(): void {
		$payment = $this->payment();
		$authorization = $this->payPayPalWithCard($payment, false);
		$capture = $this->paypalClient()->getPaymentsController()->captureAuthorizedPayment(['authorizationId' => $authorization, 'prefer' => 'return=representation'])->getResult();
		$payment->setProviderId($capture->getId());
		if ($capture->getStatus() !== 'PENDING') {
			$this->markTestSkipped("The sandbox settled the capture right away ({$capture->getStatus()}), there is no capture in review to check");
		}

		$event = $this->paypal()->parseWebhook($payment, $this->paypalEvent('PAYMENT.CAPTURE.PENDING', fn(object $e) => $e->resource->id = $capture->getId()));

		$this->assertSame(Status::Pending, $event->status);
	}
}
