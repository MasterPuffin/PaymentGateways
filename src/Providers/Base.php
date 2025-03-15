<?php

namespace MasterPuffin\PaymentGateways\Providers;

class Base {
	protected array $credentials;
	protected array $options;

	public function getSuccessUrl(): string {
		return $this->successUrl;
	}

	public function getCancelUrl(): string {
		return $this->cancelUrl;
	}
	protected string $successUrl;
	protected string $cancelUrl;
	protected bool $sandbox = false;

	public function setCredentials(array $credentials): void {
		$this->credentials = $credentials;
	}

	public function setOptions(array $options): void {
		$this->options = $options;
	}

	public function setSuccessUrl(string $successUrl): void {
		$this->successUrl = $successUrl;
	}

	public function setCancelUrl(string $cancelUrl): void {
		$this->cancelUrl = $cancelUrl;
	}

	public function setSandbox(bool $sandbox): void {
		$this->sandbox = $sandbox;
	}
}