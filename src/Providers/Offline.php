<?php

namespace MasterPuffin\PaymentGateways\Providers;


use MasterPuffin\PaymentGateways\Exceptions\GatewayException;
use MasterPuffin\PaymentGateways\Payment;
use MasterPuffin\PaymentGateways\ProviderInterface;
use MasterPuffin\PaymentGateways\Status;
use MasterPuffin\PaymentGateways\WebhookEvent;
use Psr\Http\Message\RequestInterface;

class Offline extends Base implements ProviderInterface {
	public function create(Payment $payment): string {
		return $this->successUrl;
	}

	public function execute(Payment $payment): Status {
		$payment->setStatus(Status::Pending);
		return Status::Pending;
	}

	/**
	 * Nobody to ask: the stored status stays.
	 */
	public function fetchStatus(Payment $payment): WebhookEvent {
		return new WebhookEvent($payment->getStatus());
	}

	public function cancel(Payment $payment): void {
		$payment->setStatus(Status::Cancelled);
	}

	/**
	 * @throws GatewayException
	 */
	public function refund(Payment $payment, ?float $amount = null): void {
		throw new GatewayException("Refund is not possible with offline provider");
	}

	/**
	 * @throws GatewayException
	 */
	public function getStatusFromWebhook(Payment $payment, RequestInterface|null $request = null): Status {
		throw new GatewayException("getStatusFromWebhook is not possible with offline provider");
	}

	public function getProviderIdFromWebhook(RequestInterface $request): ?string {
		return null;
	}

	public function getProviderIdsFromWebhook(RequestInterface $request): array {
		return [];
	}

	/**
	 * @throws GatewayException
	 */
	public function parseWebhook(Payment $payment, RequestInterface $request): WebhookEvent {
		throw new GatewayException("parseWebhook is not possible with offline provider");
	}
}