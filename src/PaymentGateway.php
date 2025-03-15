<?php

use Exceptions\GatewayException;
use Exceptions\InvalidCredentialsException;
use Exceptions\InvalidOptionsException;
use Providers\Offline;
use Providers\PayPal;
use Providers\Stripe;

class PaymentGateway {
	public object $providerClass;
	private Provider $provider;

	/**
	 * @throws InvalidCredentialsException
	 * @throws InvalidOptionsException
	 */
	public function __construct(Provider $provider, array $credentials = [], array $options = []) {
		switch ($provider) {
			case Provider::PayPal:
				$this->providerClass = new PayPal();
				if (!array_key_exists('client_id', $credentials)) throw new InvalidCredentialsException("client_id is required");
				if (!array_key_exists('client_secret', $credentials)) throw new InvalidCredentialsException("client_secret is required");
				break;
			case Provider::Stripe:
				$this->providerClass = new Stripe();
				if (!array_key_exists('secret_key', $credentials)) throw new InvalidCredentialsException("secret_key is required");
				break;
			case Provider::Offline:
				$this->providerClass = new Offline();
				break;
			default:
				throw new InvalidCredentialsException("Invalid provider");
		}
		$this->providerClass->credentials = $credentials;
		$this->providerClass->options = $options;
	}

	/**
	 * @throws InvalidOptionsException
	 * @throws GatewayException
	 */
	public function create(Payment $payment) {
		if (empty($this->providerClass->successUrl)) throw new InvalidOptionsException("successUrl is required");
		if (empty($this->providerClass->cancelUrl)) throw new InvalidOptionsException("cancelUrl is required");
		$payment->provider = $this->provider;
		return $this->providerClass->create($payment);
	}

	/**
	 * @throws InvalidOptionsException
	 */
	public function execute(Payment $payment) {
		if (empty($payment->providerId)) throw new InvalidOptionsException("providerId is required");
		return $this->providerClass->execute($payment);
	}

	public function setSuccessUrl(string $url): void {
		$this->providerClass->successUrl = $url;
	}

	public function setCancelUrl(string $url): void {
		$this->providerClass->cancelUrl = $url;
	}
}