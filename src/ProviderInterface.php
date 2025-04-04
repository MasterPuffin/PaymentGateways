<?php

namespace MasterPuffin\PaymentGateways;

use Psr\Http\Message\RequestInterface;

interface ProviderInterface {
	public function create(Payment $payment): ?string;

	public function execute(Payment $payment): Status;

	public function refund(Payment $payment): void;

	public function getStatusFromWebhook(Payment $payment, ?RequestInterface $request = null): Status;
}