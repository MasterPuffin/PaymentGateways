<?php

class Payment {
	public string $id;
	public Provider $provider;
	public ?string $providerId = null;
	public float $amount = 0.0;
	public string $currencyCode = 'EUR';
	public string $description = '';
	public array $metadata = [];
	public Status $status = Status::Open;
	public Customer $customer;

	public function __construct() {
		$this->customer = new Customer();
	}
}
