<?php

use Exceptions\GatewayException;
use Exceptions\InvalidCredentialsException;
use Exceptions\InvalidOptionsException;
use Exceptions\NotImplementedException;
use Providers\Offline;
use Providers\PayPal;
use Providers\Stripe;

class PaymentGateway {
	private object $providerClass;
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
				if (!array_key_exists('webhook_secret', $credentials)) throw new InvalidCredentialsException("webhook_secret is required");
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
		$payment->setProvider($this->provider);
		return $this->providerClass->create($payment);
	}

	/**
	 * @throws InvalidOptionsException
	 */
	public function execute(Payment $payment): Status {
		if (empty($payment->getProviderId())) throw new InvalidOptionsException("providerId is required");
		return $this->providerClass->execute($payment);
	}

	/**
	 * @throws InvalidOptionsException
	 * @throws NotImplementedException
	 * @throws GatewayException
	 */
	public function refund(Payment $payment, ?float $amount = null): void {
		if (empty($payment->getProviderId())) throw new InvalidOptionsException("providerId is required");
		$this->providerClass->refund($payment, $amount);
	}

	/**
	 * @throws InvalidOptionsException
	 * @throws NotImplementedException
	 * @throws GatewayException
	 */
	public function getStatusFromWebhook(Payment $payment, string $payload): Status {
		if (empty($payment->getProviderId())) throw new InvalidOptionsException("providerId is required");
		return $this->providerClass->getStatusFromWebhook($payment, $payload);
	}

	public function setSuccessUrl(string $url): void {
		$this->providerClass->successUrl = $url;
	}

	public function setCancelUrl(string $url): void {
		$this->providerClass->cancelUrl = $url;
	}
}