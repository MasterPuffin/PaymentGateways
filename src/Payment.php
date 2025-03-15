<?php

class Payment {
	private string $id;
	private Provider $provider;
	private ?string $providerId = null;
	private float $amount = 0.0;
	private string $currencyCode = 'EUR';
	private string $description = '';
	private array $metadata = [];
	private Status $status = Status::Open;
	private Customer $customer;

	public function __construct() {
		$this->customer = new Customer();
	}

	public function getId(): string {
		return $this->id;
	}

	public function setId(string $id): void {
		$this->id = $id;
	}

	public function getProvider(): Provider {
		return $this->provider;
	}

	public function setProvider(Provider $provider): void {
		$this->provider = $provider;
	}

	public function getProviderId(): ?string {
		return $this->providerId;
	}

	public function setProviderId(?string $providerId): void {
		$this->providerId = $providerId;
	}

	public function getAmount(): float {
		return $this->amount;
	}

	public function setAmount(float $amount): void {
		$this->amount = $amount;
	}

	public function getCurrencyCode(): string {
		return $this->currencyCode;
	}

	public function setCurrencyCode(string $currencyCode): void {
		$this->currencyCode = $currencyCode;
	}

	public function getDescription(): string {
		return $this->description;
	}

	public function setDescription(string $description): void {
		$this->description = $description;
	}

	public function getMetadata(): array {
		return $this->metadata;
	}

	public function setMetadata(array $metadata): void {
		$this->metadata = $metadata;
	}

	public function getStatus(): Status {
		return $this->status;
	}

	public function setStatus(Status $status): void {
		$this->status = $status;
	}

	public function getCustomer(): Customer {
		return $this->customer;
	}

	public function setCustomer(Customer $customer): void {
		$this->customer = $customer;
	}
}
