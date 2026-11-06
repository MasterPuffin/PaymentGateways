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
	 * Currencies without minor unit, their amounts are whole numbers (https://docs.stripe.com/currencies#zero-decimal)
	 */
	private const array ZERO_DECIMAL_CURRENCIES = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

	/**
	 * Converts an amount to the smallest currency unit (cents, or the amount itself for currencies without minor unit).
	 * Rounds instead of truncating, because floats like 19.99 * 100 = 1998.9999… would otherwise lose a cent.
	 */
	protected function _toMinorUnits(float $amount, string $currency): int {
		return (int)round($amount * $this->_minorUnitFactor($currency));
	}

	/**
	 * Converts an amount in the smallest currency unit back, see {@see _toMinorUnits()}.
	 */
	protected function _fromMinorUnits(int|float $amount, string $currency): float {
		return $amount / $this->_minorUnitFactor($currency);
	}

	private function _minorUnitFactor(string $currency): int {
		return in_array(strtoupper($currency), self::ZERO_DECIMAL_CURRENCIES, true) ? 1 : 100;
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
		return is_null($amount) || $this->_toMinorUnits($amount, $payment->getCurrencyCode()) >= $this->_toMinorUnits($payment->getAmount(), $payment->getCurrencyCode());
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