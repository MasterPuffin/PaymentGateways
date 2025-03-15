<?php

use Providers\Offline;
use Providers\PayPal;
use Providers\Stripe;

class PaymentGateway {
	public object $provider;

	public function __construct(Provider $provider) {
		switch ($provider) {
			case Provider::PayPal:
				$this->provider = new PayPal();
				break;
			case Provider::Stripe:
				$this->provider = new Stripe();
				break;
			case Provider::Offline:
				$this->provider = new Offline();
				break;
		}
	}

	public function create(Payment $payment) {
		return $this->provider->create($payment);
	}

	public function execute() {
		return $this->provider->execute();
	}
}