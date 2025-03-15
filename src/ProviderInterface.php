<?php

interface ProviderInterface {
	public function create(Payment $payment);

	public function execute();
}