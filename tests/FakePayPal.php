<?php

namespace MasterPuffin\PaymentGateways\Tests;

use MasterPuffin\PaymentGateways\Exceptions\GatewayException;
use MasterPuffin\PaymentGateways\Providers\PayPal_REST;
use PaypalServerSdkLib\PaypalServerSdkClient;

/**
 * The real PayPal provider without PayPal: only the SDK client and the dispute request are replaced, so status mapping,
 * error handling and the order of the calls run as in production.
 */
final class FakePayPal extends PayPal_REST {
	/**
	 * @param array<string, object> $disputes Answers of the disputes API by dispute id
	 */
	public function __construct(private readonly PaypalServerSdkClient $client, private readonly array $disputes = []) {
	}

	protected function _createClient(): PaypalServerSdkClient {
		return $this->client;
	}

	protected function _fetchDispute(string $disputeId): object {
		return $this->disputes[$disputeId] ?? throw new GatewayException('Dispute request failed with status 404');
	}
}
