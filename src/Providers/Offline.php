<?php

namespace Providers;

use Payment;
use Provider;
use ProviderInterface;
use Status;

class Offline extends Base implements ProviderInterface {
	public function create(Payment $payment): string {
		$this->payment = $payment;
		return $this->successUrl;
	}

	public function execute(): null {
		$this->payment->status = Status::Pending;
		return null;
	}

}