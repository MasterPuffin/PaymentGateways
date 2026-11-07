<?php

namespace MasterPuffin\PaymentGateways;

/**
 * What a webhook says about a payment, see {@see PaymentGateway::parseWebhook()}. Amounts are in the major unit of the
 * currency (12.34 EUR, 1234 JPY).
 */
final class WebhookEvent {
	/**
	 * @param Status $status Status of the payment after the event; the stored status if the event doesn't change it
	 * @param float|null $refundedTotal Sum of all refunds of the payment, null if the event doesn't tell it
	 * @param float|null $disputedAmount Amount of a chargeback, null if the event doesn't tell it (the whole payment)
	 * @param bool $checkoutCompleted The customer completed the checkout page. The payment is not settled yet: call
	 *     {@see PaymentGateway::execute()}, which also captures authorized payments
	 * @param bool $disputeWon The dispute of the payment was closed in favour of the seller, the money is back (status
	 *     Succeeded). Only this ends a chargeback: a Succeeded without it is no decision about a dispute, e.g. a
	 *     redelivered success event or the state of a PayPal capture, which stays COMPLETED while it is disputed
	 */
	public function __construct(
		public readonly Status $status,
		public readonly ?float $refundedTotal = null,
		public readonly ?float $disputedAmount = null,
		public readonly bool $checkoutCompleted = false,
		public readonly bool $disputeWon = false,
	) {
	}
}
