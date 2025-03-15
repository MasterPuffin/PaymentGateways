<?php

interface ProviderInterface {
	public function create(Payment $payment):?string;

	public function execute(Payment $payment):Status;

	public function refund(Payment $payment);
}