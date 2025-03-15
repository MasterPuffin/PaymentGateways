<?php

$payment = new Payment();
$payment->setAmount(123.45);
$payment->setCurrencyCode('EUR');
$payment->setDescription('Test payment');
$payment->getCustomer()->setName('John Doe');
$payment->getCustomer()->setEmail('john@doe.com');

$gateway = new PaymentGateway(Provider::Stripe, ['secret_key' => 'abc'], ['payment_method_types' => ['card']]);
$gateway->setSuccessUrl('https://www.example.com/success');
$gateway->setCancelUrl('https://www.example.com/success');

$url = $gateway->create($payment);

//TODO save $payment
//TODO redirect to $url

//TODO get payment after redirect
$gateway = new PaymentGateway(Provider::Stripe, ['secret_key' => 'abc'], ['payment_method_types' => ['card']]);
$gateway->execute($payment);