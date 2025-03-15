<?php

namespace Providers;

use Payment;
use Provider;
use ProviderInterface;
use Status;

class Offline extends Base implements ProviderInterface {
	public function create(Payment $payment): null {
		$this->payment = $payment;
		return null;
	}

	public function execute(): null {
		$this->payment->status = Status::Pending;
		return null;
	}

}