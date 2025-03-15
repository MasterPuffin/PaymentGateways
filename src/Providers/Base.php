<?php

namespace Providers;

use Payment;
use Provider;
use ProviderInterface;

class Base {
	public array $credentials;
	public array $options;
	public string $successUrl;
	public string $cancelUrl;
}