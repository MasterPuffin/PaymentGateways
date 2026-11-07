<?php

namespace MasterPuffin\PaymentGateways\Tests;

use Exception;
use MasterPuffin\PaymentGateways\Exceptions\GatewayException;
use MasterPuffin\PaymentGateways\Payment;
use MasterPuffin\PaymentGateways\Status;
use Nyholm\Psr7\Factory\Psr17Factory;
use PaypalServerSdkLib\Controllers\OrdersController;
use PaypalServerSdkLib\Controllers\PaymentsController;
use PaypalServerSdkLib\Http\ApiResponse;
use PaypalServerSdkLib\Models\AuthorizationWithAdditionalData;
use PaypalServerSdkLib\Models\CapturedPayment;
use PaypalServerSdkLib\Models\Money;
use PaypalServerSdkLib\Models\Order;
use PaypalServerSdkLib\Models\OrderAuthorizeResponse;
use PaypalServerSdkLib\Models\PaymentCollection;
use PaypalServerSdkLib\Models\PurchaseUnit;
use PaypalServerSdkLib\Models\LinkDescription;
use PaypalServerSdkLib\Models\Refund;
use PaypalServerSdkLib\Models\SellerPayableBreakdown;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * What PayPal's sandbox can't produce on request, with the SDK client replaced ({@see FakePayPal}): disputes (they can't
 * be opened through the API), the return after a buyer approved an order, capture statuses a card payment never gets,
 * and failures (unexpected answers, network errors). Everything else is tested against the sandbox in {@see PayPalTest}.
 */
class PayPalOfflineTest extends TestCase {
	private function payment(Status $status = Status::Succeeded): Payment {
		$payment = new Payment();
		$payment->setProviderId('CAPTURE1');
		$payment->setAmount(10.0);
		$payment->setCurrencyCode('EUR');
		$payment->setStatus($status);
		$payment->setDescription('Test');
		$payment->getCustomer()->setEmail('test@example.com');
		return $payment;
	}

	private function webhook(array $payload): RequestInterface {
		$factory = new Psr17Factory();
		return $factory->createRequest('POST', 'https://example.com/webhook')->withBody($factory->createStream(json_encode($payload)));
	}

	private function response(object $result): ApiResponse {
		$response = $this->createStub(ApiResponse::class);
		$response->method('isError')->willReturn(false);
		$response->method('getResult')->willReturn($result);
		return $response;
	}

	private function capture(?string $status, ?string $id = 'CAPTURE1', string $amount = '10.00'): CapturedPayment {
		$capture = new CapturedPayment();
		$capture->setId($id);
		$capture->setStatus($status);
		$capture->setAmount(new Money('EUR', $amount));
		return $capture;
	}

	private function refundResult(string $status): Refund {
		$refund = new Refund();
		$refund->setStatus($status);
		return $refund;
	}

	private function authorization(?string $authorizationId): OrderAuthorizeResponse {
		$authorization = new AuthorizationWithAdditionalData();
		$authorization->setId($authorizationId);
		$payments = new PaymentCollection();
		$payments->setAuthorizations([$authorization]);
		$unit = $this->createStub(PurchaseUnit::class);
		$unit->method('getPayments')->willReturn($payments);
		$response = new OrderAuthorizeResponse();
		$response->setPurchaseUnits([$unit]);
		return $response;
	}

	private function provider(?PaymentsController $payments = null, ?OrdersController $orders = null, array $disputes = []): FakePayPal {
		$client = $this->createStub(PaypalServerSdkClient::class);
		$client->method('getPaymentsController')->willReturn($payments ?? $this->createStub(PaymentsController::class));
		$client->method('getOrdersController')->willReturn($orders ?? $this->createStub(OrdersController::class));
		return new FakePayPal($client, $disputes);
	}

	/**
	 * A provider whose API reports the capture with this status.
	 */
	private function providerWithCapture(string $status, array $disputes = []): FakePayPal {
		$payments = $this->createStub(PaymentsController::class);
		$payments->method('getCapturedPayment')->willReturn($this->response($this->capture($status)));
		return $this->provider($payments, null, $disputes);
	}

	/**
	 * Reads the ids of a body without resource.
	 * Why: The endpoint is public, any body can arrive and must simply find no payment.
	 */
	public function testAWebhookWithoutResourceNamesNoPayment(): void {
		$this->assertSame([], $this->provider()->getProviderIdsFromWebhook($this->webhook(['event_type' => 'PAYMENT.CAPTURE.COMPLETED'])));
	}

	/**
	 * Parses a capture event with the statuses a sandbox card capture never gets.
	 * Why: This table decides whether a payment counts as paid. Declined and voided captures failed, an unknown status
	 * stays pending, never paid.
	 */
	public function testRareCaptureStatusesAreMapped(): void {
		foreach (['DECLINED' => Status::Failed, 'FAILED' => Status::Failed, 'VOIDED' => Status::Failed, 'AUTHORIZED' => Status::Authorized, 'SOMETHING_NEW' => Status::Pending] as $paypal => $status) {
			$event = $this->providerWithCapture($paypal)->parseWebhook($this->payment(Status::Open), $this->webhook(['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'CAPTURE1']]));

			$this->assertSame($status, $event->status, $paypal);
		}
	}

	/**
	 * Parses a dispute event the disputes API confirms as open for this capture.
	 * Why: The normal chargeback, the money is gone. Amount and id come from the API: a capture can have several
	 * disputes of part of its amount, each is booked once.
	 */
	public function testAConfirmedDisputeIsReported(): void {
		$disputes = ['DISPUTE1' => (object)['status' => 'OPEN', 'dispute_amount' => (object)['currency_code' => 'EUR', 'value' => '3.50'], 'disputed_transactions' => [(object)['seller_transaction_id' => 'CAPTURE1']]]];

		$event = $this->providerWithCapture('COMPLETED', $disputes)->parseWebhook($this->payment(), $this->webhook(['event_type' => 'CUSTOMER.DISPUTE.CREATED', 'resource' => ['dispute_id' => 'DISPUTE1']]));

		$this->assertSame([Status::Disputed, 3.5, 'DISPUTE1'], [$event->status, $event->disputedAmount, $event->disputeId]);
	}

	/**
	 * Parses a dispute event for a real dispute of another capture.
	 * Why: A forged event could name any dispute id, it must only count for the capture it belongs to.
	 */
	public function testADisputeOfAnotherCaptureIsIgnored(): void {
		$disputes = ['DISPUTE1' => (object)['status' => 'OPEN', 'disputed_transactions' => [(object)['seller_transaction_id' => 'OTHER']]]];

		$event = $this->providerWithCapture('COMPLETED', $disputes)->parseWebhook($this->payment(), $this->webhook(['event_type' => 'CUSTOMER.DISPUTE.CREATED', 'resource' => ['dispute_id' => 'DISPUTE1']]));

		$this->assertSame(Status::Succeeded, $event->status);
	}

	/**
	 * Parses the end of a dispute of this capture, once won (resolved for the seller, capture completed) and once lost
	 * (capture refunded).
	 * Why: COMPLETED after a dispute is what brings the money back in the caller's ledger, so only a dispute resolved for
	 * the seller carries `disputeWon`, with its id and amount so the caller ends exactly that chargeback.
	 */
	public function testAResolvedDisputeShowsTheOutcomeOfTheCapture(): void {
		$dispute = fn(string $outcome) => ['DISPUTE1' => (object)['dispute_id' => 'DISPUTE1', 'status' => 'RESOLVED', 'dispute_amount' => (object)['currency_code' => 'EUR', 'value' => '4.00'], 'dispute_outcome' => (object)['outcome_code' => $outcome], 'disputed_transactions' => [(object)['seller_transaction_id' => 'CAPTURE1']]]];
		$webhook = $this->webhook(['event_type' => 'CUSTOMER.DISPUTE.RESOLVED', 'resource' => ['dispute_id' => 'DISPUTE1']]);

		$won = $this->providerWithCapture('COMPLETED', $dispute('RESOLVED_SELLER_FAVOUR'))->parseWebhook($this->payment(Status::Disputed), $webhook);
		$lost = $this->providerWithCapture('REFUNDED', $dispute('RESOLVED_BUYER_FAVOUR'))->parseWebhook($this->payment(Status::Disputed), $webhook);

		$this->assertSame([Status::Succeeded, true, 'DISPUTE1', 4.0], [$won->status, $won->disputeWon, $won->disputeId, $won->disputedAmount]);
		$this->assertSame([Status::Refunded, false], [$lost->status, $lost->disputeWon]);
	}

	/**
	 * Parses a resolved dispute of this capture whose outcome is for the buyer while the capture is still COMPLETED.
	 * Why: Only the outcome code tells who won. Taken from the capture alone, a dispute resolved for the buyer (before
	 * PayPal reverses the capture) would end the chargeback in the caller's ledger.
	 */
	public function testADisputeResolvedForTheBuyerIsNotWon(): void {
		$disputes = ['DISPUTE1' => (object)['status' => 'RESOLVED', 'dispute_outcome' => (object)['outcome_code' => 'RESOLVED_BUYER_FAVOUR'], 'disputed_transactions' => [(object)['seller_transaction_id' => 'CAPTURE1']]]];
		$webhook = $this->webhook(['event_type' => 'CUSTOMER.DISPUTE.RESOLVED', 'resource' => ['dispute_id' => 'DISPUTE1']]);

		$event = $this->providerWithCapture('COMPLETED', $disputes)->parseWebhook($this->payment(Status::Disputed), $webhook);

		$this->assertSame([Status::Disputed, false], [$event->status, $event->disputeWon]);
	}

	/**
	 * Parses events of a disputed payment whose capture is still COMPLETED: a capture event, an open dispute of this
	 * capture, and a resolved dispute of another capture.
	 * Why: The capture stays COMPLETED while it is disputed. Read as Succeeded, a redelivered or forged (the payload is
	 * unsigned) event would end a chargeback that is still open: money shown that is gone.
	 */
	public function testACompletedCaptureDoesNotEndAnOpenDispute(): void {
		$disputes = [
			'OPEN' => (object)['status' => 'WAITING_FOR_SELLER_RESPONSE', 'disputed_transactions' => [(object)['seller_transaction_id' => 'CAPTURE1']]],
			'OTHER' => (object)['status' => 'RESOLVED', 'disputed_transactions' => [(object)['seller_transaction_id' => 'CAPTURE2']]],
		];
		$provider = $this->providerWithCapture('COMPLETED', $disputes);

		foreach ([
			['event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource' => ['id' => 'CAPTURE1']],
			['event_type' => 'CUSTOMER.DISPUTE.UPDATED', 'resource' => ['dispute_id' => 'OPEN']],
			['event_type' => 'CUSTOMER.DISPUTE.RESOLVED', 'resource' => ['dispute_id' => 'OTHER']],
		] as $payload) {
			$event = $provider->parseWebhook($this->payment(Status::Disputed), $this->webhook($payload));

			$this->assertSame([Status::Disputed, false], [$event->status, $event->disputeWon], $payload['event_type']);
		}
	}

	/**
	 * A provider whose API reports a partly refunded capture and refund REFUND1 of the given capture.
	 */
	private function providerWithRefund(string $captureId): FakePayPal {
		$breakdown = new SellerPayableBreakdown();
		$breakdown->setTotalRefundedAmount(new Money('EUR', '4.00'));
		$refund = $this->refundResult('COMPLETED');
		$refund->setSellerPayableBreakdown($breakdown);
		$refund->setLinks([new LinkDescription('https://api.paypal.com/v2/payments/captures/' . $captureId, 'up')]);
		$payments = $this->createStub(PaymentsController::class);
		$payments->method('getCapturedPayment')->willReturn($this->response($this->capture('PARTIALLY_REFUNDED')));
		$payments->method('getRefund')->willReturn($this->response($refund));
		return $this->provider($payments);
	}

	/**
	 * Parses the event of a partial refund made in the PayPal dashboard.
	 * Why: Without the sum the money that went back stays unknown to the ledger. The payload is unsigned, so it comes
	 * from the API.
	 */
	public function testARefundEventReportsTheTotalFromTheApi(): void {
		$webhook = $this->webhook(['event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => ['id' => 'REFUND1', 'amount' => ['value' => '999.00']]]);

		$event = $this->providerWithRefund('CAPTURE1')->parseWebhook($this->payment(), $webhook);

		$this->assertSame(Status::PartiallyRefunded, $event->status);
		$this->assertSame(4.0, $event->refundedTotal);
	}

	/**
	 * Parses a refund event that names the refund of another capture.
	 * Why: Anyone can post to the endpoint. A foreign refund must not count for this payment.
	 */
	public function testARefundOfAnotherCaptureHasNoTotal(): void {
		$webhook = $this->webhook(['event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => ['id' => 'REFUND1']]);

		$this->assertNull($this->providerWithRefund('OTHER')->parseWebhook($this->payment(), $webhook)->refundedTotal);
	}

	/**
	 * Parses a dispute event whose dispute the API doesn't know.
	 * Why: A failed check must fail the webhook, so PayPal retries it. It must not be read as "no dispute".
	 */
	public function testAFailedDisputeRequestFailsTheWebhook(): void {
		$this->expectException(GatewayException::class);

		$this->providerWithCapture('COMPLETED')->parseWebhook($this->payment(), $this->webhook(['event_type' => 'CUSTOMER.DISPUTE.CREATED', 'resource' => ['dispute_id' => 'UNKNOWN']]));
	}

	/**
	 * Creates an order whose answer has no link for the buyer.
	 * Why: Without a link the customer can't pay, this must be an error and not an empty redirect.
	 */
	public function testAnOrderWithoutApprovalLinkFails(): void {
		$order = new Order();
		$order->setId('ORDER1');
		$order->setLinks([new LinkDescription('https://api.paypal.com/self', 'self')]);
		$orders = $this->createStub(OrdersController::class);
		$orders->method('createOrder')->willReturn($this->response($order));

		$this->expectException(GatewayException::class);
		$this->expectExceptionMessage('No approve link found');

		$this->provider(null, $orders)->create($this->payment(Status::Open));
	}

	/**
	 * Authorizes and captures an order the buyer approved.
	 * Why: Approving needs a buyer in the browser. Later webhooks and refunds refer to the capture, so the provider id must
	 * change from the order to the capture.
	 */
	public function testExecutingAuthorizesAndCaptures(): void {
		$orders = $this->createStub(OrdersController::class);
		$orders->method('authorizeOrder')->willReturn($this->response($this->authorization('AUTH1')));
		$payments = $this->createMock(PaymentsController::class);
		$payments->expects($this->once())->method('captureAuthorizedPayment')
			->with($this->callback(fn(array $options) => $options['authorizationId'] === 'AUTH1'))
			->willReturn($this->response($this->capture('COMPLETED', 'CAPTURE9', '12.34')));
		$payment = $this->payment(Status::Open);
		$payment->setProviderId('ORDER1');

		$status = $this->provider($payments, $orders)->execute($payment);

		$this->assertSame(Status::Succeeded, $status);
		$this->assertSame('CAPTURE9', $payment->getProviderId());
		$this->assertSame(12.34, $payment->getAmount());
	}

	/**
	 * Lets the authorization, then the capture return no id.
	 * Why: Without these ids no money moved, the payment must not look successful.
	 */
	public function testMissingIdsFailTheExecution(): void {
		$orders = $this->createStub(OrdersController::class);
		$orders->method('authorizeOrder')->willReturn($this->response($this->authorization(null)));
		try {
			$this->provider(null, $orders)->execute($this->payment(Status::Open));
			$this->fail('A missing authorization must fail');
		} catch (GatewayException $e) {
			$this->assertStringContainsString('No authorization ID', $e->getMessage());
		}

		$orders = $this->createStub(OrdersController::class);
		$orders->method('authorizeOrder')->willReturn($this->response($this->authorization('AUTH1')));
		$payments = $this->createStub(PaymentsController::class);
		$payments->method('captureAuthorizedPayment')->willReturn($this->response($this->capture('COMPLETED', null)));

		$this->expectException(GatewayException::class);
		$this->expectExceptionMessage('No capture ID');

		$this->provider($payments, $orders)->execute($this->payment(Status::Open));
	}

	/**
	 * Refunds with an accepted PENDING answer and a capture that can't be read.
	 * Why: The money is on its way. An error here would invite a second refund, so the requested amount decides.
	 */
	public function testAPendingRefundCountsEvenIfTheCaptureCannotBeRead(): void {
		$payments = $this->createStub(PaymentsController::class);
		$payments->method('refundCapturedPayment')->willReturn($this->response($this->refundResult('PENDING')));
		$payments->method('getCapturedPayment')->willThrowException(new Exception('timeout'));
		$payment = $this->payment();

		$this->provider($payments)->refund($payment, 10.0);

		$this->assertSame(Status::Refunded, $payment->getStatus());
	}

	/**
	 * Refunds with an answer PayPal didn't accept.
	 * Why: No money moved, the caller must not book a refund.
	 */
	public function testARejectedRefundFails(): void {
		$payments = $this->createStub(PaymentsController::class);
		$payments->method('refundCapturedPayment')->willReturn($this->response($this->refundResult('CANCELLED')));

		$this->expectException(GatewayException::class);
		$this->expectExceptionMessage('CANCELLED');

		$this->provider($payments)->refund($this->payment(), 4.0);
	}

	/**
	 * Lets the API answer with an error response without details.
	 * Why: Callers only catch GatewayException, also for an answer nobody expected.
	 */
	public function testUnknownErrorResponsesNameTheStatusCode(): void {
		$response = $this->createStub(ApiResponse::class);
		$response->method('isError')->willReturn(true);
		$response->method('getBody')->willReturn('not json');
		$response->method('getStatusCode')->willReturn(503);
		$payments = $this->createStub(PaymentsController::class);
		$payments->method('refundCapturedPayment')->willReturn($response);

		$this->expectException(GatewayException::class);
		$this->expectExceptionMessage('status 503');

		$this->provider($payments)->refund($this->payment(), 4.0);
	}

	/**
	 * Lets the SDK throw, as it does for a network error.
	 * Why: Same reason, an SDK exception must not reach the caller as something else.
	 */
	public function testSdkExceptionsBecomeGatewayExceptions(): void {
		$payments = $this->createStub(PaymentsController::class);
		$payments->method('voidPayment')->willThrowException(new Exception('network down'));

		$this->expectException(GatewayException::class);
		$this->expectExceptionMessage('network down');

		$this->provider($payments)->cancel($this->payment(Status::Authorized));
	}
}
