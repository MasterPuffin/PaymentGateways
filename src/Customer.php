<?php

class Customer {
	public string $name = '';
	public string $email = '';

	public function getName(): string {
		return $this->name;
	}

	public function setName(string $name): void {
		$this->name = $name;
	}

	public function getEmail(): string {
		return $this->email;
	}

	public function setEmail(string $email): void {
		$this->email = $email;
	}
}