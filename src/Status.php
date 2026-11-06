<?php

namespace MasterPuffin\PaymentGateways;
enum Status: string {
	case Open = 'open';
	case Succeeded = 'succeeded';
	case Pending = 'pending';
	case Failed = 'failed';
	case PartiallyRefunded = 'partially_refunded';
	case Refunded = 'refunded';
	case Disputed = 'disputed';
	case Authorized = 'authorized';
	case Cancelled = 'cancelled';

	/**
	 * Whether money moved: paid, (partially) refunded or charged back.
	 */
	public function isSettled(): bool {
		return in_array($this, [self::Succeeded, self::PartiallyRefunded, self::Refunded, self::Disputed], true);
	}

	/**
	 * Whether this status comes after `$reported` in the life of a payment, so a report of `$reported` is outdated.
	 * Providers deliver webhooks in any order and retry them, e.g. a failed first attempt after the successful second
	 * one, or the success of a payment after its refund.
	 */
	public function supersedes(self $reported): bool {
		if ($this->isSettled() && !$reported->isSettled()) {
			return true;
		}
		return match ($this) {
			self::Refunded => in_array($reported, [self::Succeeded, self::PartiallyRefunded], true),
			self::PartiallyRefunded => $reported === self::Succeeded,
			default => false,
		};
	}
}