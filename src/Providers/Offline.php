<?php

namespace Providers;

use Exceptions\NotImplementedException;
use Payment;
use Provider;
use ProviderInterface;
use Status;

class Offline extends Base implements ProviderInterface {
	public function create(Payment $payment): string {
		return $this->successUrl;
	}

	public function execute(Payment $payment): Status {
		$payment->status = Status::Pending;
		return Status::Pending;
	}

	/**
	 * @throws NotImplementedException
	 */
	public function refund(Payment $payment): void {
		throw new NotImplementedException("Refund is not implemented");
	}

	/**
	 * @throws NotImplementedException
	 */
	public function getStatusFromWebhook(Payment $payment, string $payload): Status {
		throw new NotImplementedException("handleWebhook is not implemented");
	}
}