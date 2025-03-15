<?php

namespace Providers;

use Exceptions\NotImplementedException;
use Payment;
use ProviderInterface;
use Status;

class PayPal implements ProviderInterface {
	/**
	 * @param Payment $payment
	 * @throws NotImplementedException
	 */
	public function create(Payment $payment): ?string {
		throw new NotImplementedException("handleWebhook is not implemented");
	}

	/**
	 * @param Payment $payment
	 * @throws NotImplementedException
	 */
	public function execute(Payment $payment): Status {
		throw new NotImplementedException("handleWebhook is not implemented");
	}

	/**
	 * @throws NotImplementedException
	 */
	public function refund(Payment $payment): void {
		throw new NotImplementedException("handleWebhook is not implemented");
	}

	/**
	 * @throws NotImplementedException
	 */
	public function getStatusFromWebhook(Payment $payment, string $payload): Status {
		throw new NotImplementedException("handleWebhook is not implemented");
	}
}