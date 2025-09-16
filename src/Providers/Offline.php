<?php

namespace MasterPuffin\PaymentGateways\Providers;


use MasterPuffin\PaymentGateways\Exceptions\GatewayException;
use MasterPuffin\PaymentGateways\Payment;
use MasterPuffin\PaymentGateways\ProviderInterface;
use MasterPuffin\PaymentGateways\Status;
use Psr\Http\Message\RequestInterface;

class Offline extends Base implements ProviderInterface {
	public function create(Payment $payment): string {
		return $this->successUrl;
	}

	public function execute(Payment $payment): Status {
		$payment->setStatus(Status::Pending);
		return Status::Pending;
	}

	public function cancel(Payment $payment): void {
		$payment->setStatus(Status::Cancelled);
	}

	/**
	 * @throws GatewayException
	 */
	public function refund(Payment $payment): void {
		throw new GatewayException("Refund is not possible with offline provider");
	}

	/**
	 * @throws GatewayException
	 */
	public function getStatusFromWebhook(Payment $payment, RequestInterface|null $request = null): Status {
		throw new GatewayException("getStatusFromWebhook is not possible with offline provider");
	}
}