<?php

namespace Providers;

use Payment;
use Provider;
use ProviderInterface;

class Base {
	public Payment $payment;
	public array $credentials;
	public array $options;
	public string $successUrl;
	public string $cancelUrl;

	//protected function setPayment(Payment $payment): void {
	//	$this->payment = $payment;
	//}
}