<?php

enum Provider: string {
	case PayPal = 'paypal';
	case Stripe = 'stripe';
	case Offline = 'offline';
}