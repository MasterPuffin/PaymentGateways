<?php

namespace MasterPuffin\PaymentGateways;

/**
 * What a webhook says about a payment, see {@see PaymentGateway::parseWebhook()}. Amounts are in the major unit of the
 * currency (12.34 EUR, 1234 JPY).
 */
final class WebhookEvent {
	/** The provider refused the payment itself as too risky (e.g. Stripe Radar), the bank was not asked */
	public const string REASON_BLOCKED = 'blocked';
	/** The bank or card issuer declined the payment (or the payment method was invalid) */
	public const string REASON_DECLINED = 'declined';

	/**
	 * @param Status $status Status of the payment after the event; the stored status if the event doesn't change it
	 * @param float|null $refundedTotal Sum of all refunds of the payment, null if the event doesn't tell it
	 * @param float|null $disputedAmount Amount of a chargeback, null if the event doesn't tell it (the whole payment)
	 * @param bool $checkoutCompleted The customer completed the checkout page. The payment is not settled yet: call
	 *     {@see PaymentGateway::execute()}, which also captures authorized payments
	 * @param bool $disputeWon The dispute of the payment was closed in favour of the seller, the money is back (status
	 *     Succeeded). Only this ends a chargeback: a Succeeded without it is no decision about a dispute, e.g. a
	 *     redelivered success event or the state of a PayPal capture, which stays COMPLETED while it is disputed. With
	 *     $disputedAmount the amount that comes back, null if no funds were withdrawn (Stripe inquiry) or it is unknown
	 * @param string|null $disputeId The provider's id of the dispute the event is about, null for other events. Dispute
	 *     events are delivered repeatedly and in any order; book each dispute once by this id
	 * @param int|null $occurredAt Unix time at which the reported status came about at the provider, null if unknown.
	 *     Webhooks carry the time of the event. A status check ({@see PaymentGateway::fetchStatus()}) knows it for
	 *     payments without money movement (Pending, Authorized, Failed, Cancelled), e.g. the failed charge or the
	 *     cancellation; for the other statuses it is null. Report it instead of the time of the check: without webhooks
	 *     a failure may only be noticed hours later
	 * @param string|null $failureReason Why the latest attempt to pay failed, if it did: {@see self::REASON_BLOCKED} or
	 *     {@see self::REASON_DECLINED}; null if nothing failed or the provider doesn't tell (e.g. in webhooks)
	 */
	public function __construct(
		public readonly Status $status,
		public readonly ?float $refundedTotal = null,
		public readonly ?float $disputedAmount = null,
		public readonly bool $checkoutCompleted = false,
		public readonly bool $disputeWon = false,
		public readonly ?string $disputeId = null,
		public readonly ?int $occurredAt = null,
		public readonly ?string $failureReason = null,
	) {
	}

	/**
	 * The same event with the time it occurred at the provider (and the failure reason), see the constructor.
	 */
	public function withOccurrence(?int $occurredAt, ?string $failureReason = null): self {
		return new self($this->status, $this->refundedTotal, $this->disputedAmount, $this->checkoutCompleted,
			$this->disputeWon, $this->disputeId, $occurredAt, $failureReason ?? $this->failureReason);
	}
}
