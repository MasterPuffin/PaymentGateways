<?php

namespace MasterPuffin\PaymentGateways;
enum Provider: string {
	case PayPal_REST = 'paypal_rest';
	case Stripe_Checkout = 'stripe_checkout';
	case Offline = 'offline';
}