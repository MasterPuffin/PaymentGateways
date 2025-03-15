<?php

$payment = new Payment();
$payment->amount = 123.45;
$payment->currencyCode = 'EUR';
$payment->description = 'Test payment';
$payment->customer->name = 'John Doe';
$payment->customer->email = 'john@doe.com';

$gateway = new PaymentGateway(Provider::Stripe, ['secret_key' => 'abc']);
$gateway->setSuccessUrl('https://www.example.com/success');
$gateway->setCancelUrl('https://www.example.com/success');

$payment = $gateway->create($payment);