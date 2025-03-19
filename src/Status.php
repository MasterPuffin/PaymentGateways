<?php

namespace MasterPuffin\PaymentGateways;
enum Status: string {
	case Open = 'open';
	case Succeeded = 'succeeded';
	case Pending = 'pending';
	case Failed = 'failed';
	case PartiallyRefunded = 'partially_refunded';
	case Refunded = 'refunded';
}