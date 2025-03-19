<?php

namespace MasterPuffin\PaymentGateways;


use MasterPuffin\PaymentGateways\Exceptions\GatewayException;
use MasterPuffin\PaymentGateways\Exceptions\InvalidCredentialsException;
use MasterPuffin\PaymentGateways\Exceptions\InvalidOptionsException;
use MasterPuffin\PaymentGateways\Exceptions\NotImplementedException;
use MasterPuffin\PaymentGateways\Providers\Offline;
use MasterPuffin\PaymentGateways\Providers\PayPal_REST;
use MasterPuffin\PaymentGateways\Providers\Stripe_Checkout;

class PaymentGateway {
	private object $providerClass;
	private Provider $provider;

	/**
	 * @throws InvalidCredentialsException
	 */
	public function __construct(Provider $provider, array $credentials = [], array $options = []) {
		switch ($provider) {
			case Provider::PayPal_REST:
				$this->providerClass = new PayPal_REST();
				if (!array_key_exists('client_id', $credentials)) throw new InvalidCredentialsException("client_id is required");
				if (!array_key_exists('client_secret', $credentials)) throw new InvalidCredentialsException("client_secret is required");
				break;
			case Provider::Stripe_Checkout:
				$this->providerClass = new Stripe_Checkout();
				if (!array_key_exists('secret_key', $credentials)) throw new InvalidCredentialsException("secret_key is required");
				if (!array_key_exists('webhook_secret', $credentials)) throw new InvalidCredentialsException("webhook_secret is required");
				break;
			case Provider::Offline:
				$this->providerClass = new Offline();
				break;
			default:
				throw new InvalidCredentialsException("Invalid provider");
		}
		$this->provider = $provider;
		$this->providerClass->setCredentials($credentials);
		$this->providerClass->setOptions($options);
	}

	/**
	 * @throws InvalidOptionsException
	 * @throws GatewayException
	 */
	public function create(Payment $payment) {
		if (empty($this->providerClass->getSuccessUrl())) throw new InvalidOptionsException("successUrl is required");
		if (empty($this->providerClass->getCancelUrl())) throw new InvalidOptionsException("cancelUrl is required");
		$payment->setProvider($this->provider);
		return $this->providerClass->create($payment);
	}

	/**
	 * @throws InvalidOptionsException|GatewayException
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
		$this->providerClass->setSuccessUrl($url);
	}

	public function setCancelUrl(string $url): void {
		$this->providerClass->setCancelUrl($url);
	}

	public function setSandbox(bool $sandbox): void {
		$this->providerClass->setSandbox($sandbox);
	}
}