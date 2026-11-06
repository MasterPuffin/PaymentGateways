<?php

namespace MasterPuffin\PaymentGateways\Tests;

use MasterPuffin\PaymentGateways\Status;
use PHPUnit\Framework\TestCase;

/**
 * The order of payment statuses. Providers deliver webhooks in any order, so callers need to tell a real change from a
 * late report of an earlier state.
 */
class StatusTest extends TestCase {
	/**
	 * Lists the settled statuses.
	 * Why: Everything else in the library builds on this list.
	 */
	public function testSettledStatusesMovedMoney(): void {
		$settled = array_filter(Status::cases(), fn(Status $status) => $status->isSettled());

		$this->assertSame([Status::Succeeded, Status::PartiallyRefunded, Status::Refunded, Status::Disputed], array_values($settled));
	}

	/**
	 * Compares statuses that came before a later one.
	 * Why: Providers deliver webhooks in any order. A late report must not undo a payment or a refund.
	 */
	public function testALateReportOfAnEarlierStatusIsOutdated(): void {
		$this->assertTrue(Status::Succeeded->supersedes(Status::Failed));
		$this->assertTrue(Status::Succeeded->supersedes(Status::Pending));
		$this->assertTrue(Status::Refunded->supersedes(Status::Succeeded));
		$this->assertTrue(Status::Refunded->supersedes(Status::PartiallyRefunded));
		$this->assertTrue(Status::PartiallyRefunded->supersedes(Status::Succeeded));
	}

	/**
	 * Compares statuses in their normal order.
	 * Why: The opposite case: real progress must not be ignored, including a won chargeback.
	 */
	public function testLaterStatusesAreNotOutdated(): void {
		$this->assertFalse(Status::Pending->supersedes(Status::Succeeded));
		$this->assertFalse(Status::Succeeded->supersedes(Status::Refunded));
		$this->assertFalse(Status::Succeeded->supersedes(Status::Disputed));
		$this->assertFalse(Status::Disputed->supersedes(Status::Succeeded), 'A won chargeback');
		$this->assertFalse(Status::Open->supersedes(Status::Cancelled));
	}
}
