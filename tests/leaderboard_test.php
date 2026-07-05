<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace mod_agon;

use mod_agon\local\attempt;
use mod_agon\local\leaderboard;

/**
 * Tests for the cumulative leaderboard + attempts report (DB-backed).
 *
 * @package     mod_agon
 * @category    test
 * @covers      \mod_agon\local\leaderboard
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class leaderboard_test extends \advanced_testcase {
    /**
     * Give a user an attempt on an instance with a fixed total score.
     *
     * @param int $agonid
     * @param int $userid
     * @param float $score
     */
    private function record(int $agonid, int $userid, float $score): void {
        global $DB;
        $att = attempt::start($agonid, $userid);
        $att->score = $score;
        $att->state = attempt::STATE_FINISHED;
        $DB->update_record('agon_attempt', $att);
    }

    public function test_course_totals_sum_across_instances_and_rank(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $week1 = $this->getDataGenerator()->create_module('agon', ['course' => $course->id]);
        $week2 = $this->getDataGenerator()->create_module('agon', ['course' => $course->id]);
        $elsewhere = $this->getDataGenerator()->create_module('agon', ['course' => $other->id]);

        $alice = $this->getDataGenerator()->create_user(['firstname' => 'Alice', 'lastname' => 'A']);
        $bob = $this->getDataGenerator()->create_user(['firstname' => 'Bob', 'lastname' => 'B']);

        // Alice: 1.0 in week1 + 0.75 in week2 = 1.75 (cumulative across weeks).
        $this->record($week1->id, $alice->id, 1.0);
        $this->record($week2->id, $alice->id, 0.75);
        // Bob: 1.5 in week1, plus 5.0 in another course (must NOT count here).
        $this->record($week1->id, $bob->id, 1.5);
        $this->record($elsewhere->id, $bob->id, 5.0);

        $board = leaderboard::course_totals($course->id, $alice->id);
        $this->assertCount(2, $board);
        // Alice leads (1.75 > 1.5) and is marked as me.
        $this->assertSame('Alice A', $board[0]['name']);
        $this->assertEqualsWithDelta(1.75, $board[0]['pts'], 1e-9);
        $this->assertTrue($board[0]['me']);
        $this->assertSame('Bob B', $board[1]['name']);
        $this->assertEqualsWithDelta(1.5, $board[1]['pts'], 1e-9);
        $this->assertFalse($board[1]['me']);
    }

    public function test_course_totals_excludes_zero_and_is_empty_initially(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $agon = $this->getDataGenerator()->create_module('agon', ['course' => $course->id]);
        $this->assertSame([], leaderboard::course_totals($course->id));

        // A started-but-scoreless attempt should not appear.
        $user = $this->getDataGenerator()->create_user();
        attempt::start($agon->id, $user->id);
        $this->assertSame([], leaderboard::course_totals($course->id));
    }

    public function test_attempts_report_for_instance(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $agon = $this->getDataGenerator()->create_module('agon', ['course' => $course->id]);
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Cara', 'lastname' => 'C']);

        $att = attempt::start($agon->id, $user->id);
        $att->scorecrossword = 0.75;
        $att->scorequestion = 1.0;
        $att->scorecoding = 0.5;
        $att->score = 2.25;
        $att->state = attempt::STATE_FINISHED;
        $DB->update_record('agon_attempt', $att);

        $rows = leaderboard::attempts($agon->id);
        $this->assertCount(1, $rows);
        $this->assertSame('Cara C', $rows[0]['name']);
        $this->assertEqualsWithDelta(0.75, $rows[0]['crossword'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $rows[0]['question'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $rows[0]['coding'], 1e-9);
        $this->assertTrue($rows[0]['done']);
    }

    public function test_attempts_ordered_by_total_and_empty_when_none(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $agon = $this->getDataGenerator()->create_module('agon', ['course' => $course->id]);
        $this->assertSame([], leaderboard::attempts($agon->id));

        $low = $this->getDataGenerator()->create_user(['firstname' => 'Low', 'lastname' => 'L']);
        $high = $this->getDataGenerator()->create_user(['firstname' => 'High', 'lastname' => 'H']);
        $this->record($agon->id, $low->id, 0.5);
        $this->record($agon->id, $high->id, 2.5);

        $rows = leaderboard::attempts($agon->id);
        $this->assertSame(['High H', 'Low L'], array_column($rows, 'name'));
        // An unfinished attempt reports done = false.
        $this->assertTrue($rows[0]['done']);
    }

    public function test_course_totals_respects_the_limit(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $agon = $this->getDataGenerator()->create_module('agon', ['course' => $course->id]);
        for ($i = 1; $i <= 3; $i++) {
            $u = $this->getDataGenerator()->create_user(['firstname' => 'U' . $i, 'lastname' => 'X']);
            $this->record($agon->id, $u->id, (float)$i);
        }
        $board = leaderboard::course_totals($course->id, 0, 2);
        $this->assertCount(2, $board);
        // Highest first; the third (lowest) row falls off the limit.
        $this->assertSame(['U3 X', 'U2 X'], array_column($board, 'name'));
    }

    public function test_course_totals_rounds_to_two_decimals(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $agon = $this->getDataGenerator()->create_module('agon', ['course' => $course->id]);
        $u = $this->getDataGenerator()->create_user();
        $this->record($agon->id, $u->id, 1.0 / 3.0);
        $board = leaderboard::course_totals($course->id);
        $this->assertSame(0.33, $board[0]['pts']);
    }
}
