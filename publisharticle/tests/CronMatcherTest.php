<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the cron matcher functions in plugin.publisharticle.php.
 */
class CronMatcherTest extends TestCase {

    // ── publisharticle_valid_cron ──────────────────────────────────

    public function testValidCronExpressions(): void {
        $this->assertTrue(publisharticle_valid_cron('* * * * *'));
        $this->assertTrue(publisharticle_valid_cron('0 * * * *'));
        $this->assertTrue(publisharticle_valid_cron('*/15 * * * *'));
        $this->assertTrue(publisharticle_valid_cron('0 2 * * *'));
        $this->assertTrue(publisharticle_valid_cron('1,3,5 * * * *'));
        $this->assertTrue(publisharticle_valid_cron('1-30/5 * * * *'));
        $this->assertTrue(publisharticle_valid_cron('30 4 1,15 * 5'));
    }

    public function testInvalidCronExpressions(): void {
        $this->assertFalse(publisharticle_valid_cron(''));
        $this->assertFalse(publisharticle_valid_cron('* * * *'));       // 4 fields
        $this->assertFalse(publisharticle_valid_cron('* * * * * *'));   // 6 fields
        $this->assertFalse(publisharticle_valid_cron('foo bar baz qux quux'));
        $this->assertFalse(publisharticle_valid_cron('70 * * * *'));    // minute > 59
        $this->assertFalse(publisharticle_valid_cron('* 25 * * *'));    // hour > 23
        $this->assertFalse(publisharticle_valid_cron('* * 32 * *'));    // dom > 31
        $this->assertFalse(publisharticle_valid_cron('* * * 13 *'));    // month > 12
    }

    // ── publisharticle_cron_matches ────────────────────────────────

    public function testEveryMinuteMatches(): void {
        // Any timestamp matches "* * * * *"
        $this->assertTrue(publisharticle_cron_matches('* * * * *', gmmktime(10, 30, 0, 1, 15, 2026)));
        $this->assertTrue(publisharticle_cron_matches('* * * * *', gmmktime(23, 59, 0, 12, 31, 2026)));
    }

    public function testHourlyAtMinuteZero(): void {
        // 10:00 → match ; 10:45 → no match
        $this->assertTrue(publisharticle_cron_matches('0 * * * *', gmmktime(10, 0, 0, 1, 15, 2026)));
        $this->assertFalse(publisharticle_cron_matches('0 * * * *', gmmktime(10, 45, 0, 1, 15, 2026)));
        $this->assertFalse(publisharticle_cron_matches('0 * * * *', gmmktime(10, 59, 0, 1, 15, 2026)));
    }

    public function testDailyAtTwoAm(): void {
        $this->assertTrue(publisharticle_cron_matches('0 2 * * *', gmmktime(2, 0, 0, 6, 1, 2026)));
        $this->assertFalse(publisharticle_cron_matches('0 2 * * *', gmmktime(2, 30, 0, 6, 1, 2026)));
        $this->assertFalse(publisharticle_cron_matches('0 2 * * *', gmmktime(3, 0, 0, 6, 1, 2026)));
    }

    public function testEveryFifteenMinutes(): void {
        // */15 → minutes 0,15,30,45
        $this->assertTrue(publisharticle_cron_matches('*/15 * * * *', gmmktime(10, 0, 0, 1, 15, 2026)));
        $this->assertTrue(publisharticle_cron_matches('*/15 * * * *', gmmktime(10, 15, 0, 1, 15, 2026)));
        $this->assertTrue(publisharticle_cron_matches('*/15 * * * *', gmmktime(10, 45, 0, 1, 15, 2026)));
        $this->assertFalse(publisharticle_cron_matches('*/15 * * * *', gmmktime(10, 7, 0, 1, 15, 2026)));
    }

    public function testSpecificMinuteList(): void {
        // 5,35 → only minutes 5 and 35
        $this->assertTrue(publisharticle_cron_matches('5,35 * * * *', gmmktime(10, 5, 0, 1, 15, 2026)));
        $this->assertTrue(publisharticle_cron_matches('5,35 * * * *', gmmktime(10, 35, 0, 1, 15, 2026)));
        $this->assertFalse(publisharticle_cron_matches('5,35 * * * *', gmmktime(10, 20, 0, 1, 15, 2026)));
    }

    public function testRangeWithStep(): void {
        // 1-30/5 → minutes 1,6,11,16,21,26
        $forMinute = fn(int $min): bool => publisharticle_cron_matches('1-30/5 * * * *', gmmktime(10, $min, 0, 1, 15, 2026));
        $this->assertTrue($forMinute(1));
        $this->assertTrue($forMinute(6));
        $this->assertTrue($forMinute(26));
        $this->assertFalse($forMinute(2));
        $this->assertFalse($forMinute(31));
    }

    public function testMonthNames(): void {
        // "jan" matches January, not February
        $this->assertTrue(publisharticle_cron_matches('0 0 1 jan *', gmmktime(0, 0, 0, 1, 1, 2026)));
        $this->assertFalse(publisharticle_cron_matches('0 0 1 jan *', gmmktime(0, 0, 0, 2, 1, 2026)));
    }

    public function testDayOfWeekNames(): void {
        // 2026-09-13 is a Sunday → "sun" and 0/7 should match
        $sunday = gmmktime(12, 0, 0, 9, 13, 2026);
        $this->assertSame(0, (int) gmdate('w', $sunday)); // sanity check
        $this->assertTrue(publisharticle_cron_matches('* * * * sun', $sunday));
        $this->assertTrue(publisharticle_cron_matches('* * * * 0', $sunday));
        $this->assertTrue(publisharticle_cron_matches('* * * * 7', $sunday)); // 7 = Sunday
        $this->assertFalse(publisharticle_cron_matches('* * * * mon', $sunday));
    }

    public function testNonMatchingDayOfMonth(): void {
        // "0 0 15 * *" matches only on the 15th
        $this->assertTrue(publisharticle_cron_matches('0 0 15 * *', gmmktime(0, 0, 0, 6, 15, 2026)));
        $this->assertFalse(publisharticle_cron_matches('0 0 15 * *', gmmktime(0, 0, 0, 6, 14, 2026)));
    }

    public function testInvalidExpressionNeverMatches(): void {
        $this->assertFalse(publisharticle_cron_matches('', time()));
        $this->assertFalse(publisharticle_cron_matches('not a cron', time()));
        $this->assertFalse(publisharticle_cron_matches('* * * *', time()));
    }
}