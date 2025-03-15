<?php

$payment = new Payment();

$gateway = new PaymentGateway(Provider::Offline);
$payment = $gateway->create($payment);