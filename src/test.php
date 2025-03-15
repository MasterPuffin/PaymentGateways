<?php

$payment = new Payment();
$payment->amount = 123.45;
$payment->currencyCode = 'EUR';
$payment->description = 'Test payment';
$payment->customer->name = 'John Doe';
$payment->customer->email = 'john@doe.com';

$gateway = new PaymentGateway(Provider::Stripe, ['secret_key' => 'abc'], ['payment_method_types' => ['card']]);
$gateway->setSuccessUrl('https://www.example.com/success');
$gateway->setCancelUrl('https://www.example.com/success');

$url = $gateway->create($payment);

//TODO save $payment
//TODO redirect to $url