<?php

namespace MasterPuffin\PaymentGateways\Providers;

class Base {
	public array $credentials;
	public array $options;
	public string $successUrl;
	public string $cancelUrl;
}