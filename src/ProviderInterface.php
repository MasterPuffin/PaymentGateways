<?php

namespace MasterPuffin\PaymentGateways;

use Psr\Http\Message\RequestInterface;

interface ProviderInterface {
	public function create(Payment $payment): ?string;

	public function execute(Payment $payment): Status;

	public function cancel(Payment $payment): void;

	public function refund(Payment $payment, ?float $amount = null): void;

	public function getStatusFromWebhook(Payment $payment, ?RequestInterface $request = null): Status;

	/**
	 * Extracts the id the payment was saved with (see Payment::getProviderId()) from a webhook, so the payment can be
	 * looked up before {@see getStatusFromWebhook()} verifies the event. The value is not verified yet.
	 */
	public function getProviderIdFromWebhook(RequestInterface $request): ?string;

	/**
	 * All ids a payment of the webhook may be saved with, most specific first (e.g. the Checkout session and the payment
	 * intent of Stripe). Not verified yet.
	 *
	 * @return string[]
	 */
	public function getProviderIdsFromWebhook(RequestInterface $request): array;

	/**
	 * Verifies the webhook and tells what it means for the payment.
	 */
	public function parseWebhook(Payment $payment, RequestInterface $request): WebhookEvent;
}