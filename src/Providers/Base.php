<?php

namespace MasterPuffin\PaymentGateways\Providers;

use MasterPuffin\PaymentGateways\Exceptions\GatewayException;
use MasterPuffin\PaymentGateways\Payment;
use Psr\Http\Message\RequestInterface;

class Base {
	protected array $credentials;
	protected array $options;
	protected string $successUrl = '';
	protected string $cancelUrl = '';
	protected bool $sandbox = false;

	public function getSuccessUrl(): string {
		return $this->successUrl;
	}

	public function getCancelUrl(): string {
		return $this->cancelUrl;
	}

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

	/**
	 * Converts an amount to the smallest currency unit (cents). Rounds instead of truncating, because floats like
	 * 19.99 * 100 = 1998.9999… would otherwise lose a cent.
	 */
	protected function _toMinorUnits(float $amount): int {
		return (int)round($amount * 100);
	}

	/**
	 * Formats an amount as decimal string with two digits, as required by APIs that take amounts as strings.
	 */
	protected function _formatAmount(float $amount): string {
		return number_format($amount, 2, '.', '');
	}

	/**
	 * A refund without amount or with (at least) the full amount of the payment is a full refund.
	 */
	protected function _isFullRefund(Payment $payment, ?float $amount): bool {
		return is_null($amount) || $this->_toMinorUnits($amount) >= $this->_toMinorUnits($payment->getAmount());
	}

	/**
	 * Returns the raw webhook body. Casting the stream rewinds it, so it works even if the body was read before.
	 *
	 * @throws GatewayException
	 */
	protected function _getWebhookBody(?RequestInterface $request): string {
		if (is_null($request)) {
			throw new GatewayException('A request is required to process a webhook');
		}
		return (string)$request->getBody();
	}
}