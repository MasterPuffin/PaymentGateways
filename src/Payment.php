<?php

class Payment {
	public string $id;
	public Provider $provider;
	public float $amount = 0.0;
	public string $currencyCode = 'EUR';
	public string $description = '';
	public array $metadata = [];
	public Status $status = Status::Open;
}
