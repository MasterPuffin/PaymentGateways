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
}