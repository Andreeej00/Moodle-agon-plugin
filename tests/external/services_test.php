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

namespace mod_agon\external;

use core_external\external_api;

/**
 * Tests for the agon attempt web services.
 *
 * @package     mod_agon
 * @category    test
 * @covers      \mod_agon\external\start_attempt
 * @covers      \mod_agon\external\submit_game
 * @covers      \mod_agon\external\finish_attempt
 * @covers      \mod_agon\external\get_hint
 * @covers      \mod_agon\external\get_sequence
 * @covers      \mod_agon\external\get_leaderboard
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class services_test extends \advanced_testcase {
    /** @var \stdClass The course holding the activity. */
    private $course;
    /** @var \stdClass The agon instance under test. */
    private $agon;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $question = json_encode(['questions' => [
            ['question' => 'Pick B', 'options' => ['A', 'B', 'C'], 'correct' => 1, 'explanation' => 'B.'],
        ]]);
        // Only the question game is enabled, so finishing needs just that one submission.
        $this->agon = $this->getDataGenerator()->create_module('agon', [
            'course' => $this->course->id,
            'gamecrossword' => 0,
            'gamecoding' => 0,
            'contentquestion' => $question,
        ]);
    }

    public function test_full_loop_for_a_student(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);

        $started = external_api::clean_returnvalue(
            start_attempt::execute_returns(), start_attempt::execute($this->agon->cmid));
        $this->assertSame('inprogress', $started['state']);
        $this->assertGreaterThan(0, $started['timestart']);

        $submitted = external_api::clean_returnvalue(
            submit_game::execute_returns(),
            submit_game::execute($this->agon->cmid, 'question', json_encode(['selected' => 1])));
        $this->assertEqualsWithDelta(1.0, $submitted['scorequestion'], 1e-9);
        $this->assertEqualsWithDelta(1.0, $submitted['score'], 1e-9);
        $this->assertContains('question', $submitted['submittedgames']);
        // Reveal-on-submit: the now-finished game returns its answer + explanation.
        $feedback = json_decode($submitted['feedback'], true);
        $this->assertSame(1, $feedback['correct']);
        $this->assertSame('B.', $feedback['explanation']);

        $finished = external_api::clean_returnvalue(
            finish_attempt::execute_returns(), finish_attempt::execute($this->agon->cmid));
        $this->assertSame('finished', $finished['state']);
        $this->assertGreaterThan(0, $finished['timefinish']);
    }

    public function test_get_hint_eliminates_wrong_answers(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);

        $result = external_api::clean_returnvalue(
            get_hint::execute_returns(),
            get_hint::execute($this->agon->cmid, 'question', '{}'));
        $hint = json_decode($result['hint'], true);
        $this->assertSame('question', $hint['type']);
        // 3 options, correct = 1 → one wrong option removed, and it is never the answer.
        $this->assertCount(1, $hint['remove']);
        $this->assertNotContains(1, $hint['remove']);
    }

    public function test_get_leaderboard_lists_the_current_user(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        submit_game::execute($this->agon->cmid, 'question', json_encode(['selected' => 1]));

        $res = external_api::clean_returnvalue(
            get_leaderboard::execute_returns(), get_leaderboard::execute($this->agon->cmid));
        $this->assertNotEmpty($res['leaderboard']);
        $this->assertEqualsWithDelta(1.0, $res['leaderboard'][0]['pts'], 1e-9);
        $this->assertTrue($res['leaderboard'][0]['me']);
    }

    public function test_play_requires_capability(): void {
        // An editing teacher does not get mod/agon:play.
        $teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($teacher);
        $this->expectException(\required_capability_exception::class);
        start_attempt::execute($this->agon->cmid);
    }

    public function test_submit_twice_is_refused(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        submit_game::execute($this->agon->cmid, 'question', json_encode(['selected' => 1]));
        try {
            submit_game::execute($this->agon->cmid, 'question', json_encode(['selected' => 0]));
            $this->fail('Expected gamealreadysubmitted');
        } catch (\moodle_exception $e) {
            $this->assertSame('gamealreadysubmitted', $e->errorcode);
        }
    }

    public function test_hint_twice_is_refused(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        get_hint::execute($this->agon->cmid, 'question', '{}');
        try {
            get_hint::execute($this->agon->cmid, 'question', '{}');
            $this->fail('Expected hintalreadyused');
        } catch (\moodle_exception $e) {
            $this->assertSame('hintalreadyused', $e->errorcode);
        }
    }

    public function test_finish_requires_every_playable_game(): void {
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        try {
            finish_attempt::execute($this->agon->cmid);
            $this->fail('Expected attemptincomplete');
        } catch (\moodle_exception $e) {
            $this->assertSame('attemptincomplete', $e->errorcode);
        }
    }

    public function test_malformed_payload_scores_zero_not_fatal(): void {
        // A hand-crafted submission with broken JSON is treated as "no input".
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);
        $res = external_api::clean_returnvalue(
            submit_game::execute_returns(),
            submit_game::execute($this->agon->cmid, 'question', '{{{not json'));
        $this->assertEqualsWithDelta(0.0, $res['scorequestion'], 1e-9);
        $this->assertContains('question', $res['submittedgames']);
    }

    public function test_get_sequence_serves_one_coding_sequence_without_answers(): void {
        $coding = json_encode(['sequences' => [
            ['title' => 'S1', 'code' => 'a = ____', 'blanks' => ['x'], 'options' => ['x', 'y']],
            ['title' => 'S2', 'code' => 'b = ____', 'blanks' => ['z'], 'options' => ['z', 'w']],
        ]]);
        $agon = $this->getDataGenerator()->create_module('agon',
            ['course' => $this->course->id, 'contentcoding' => $coding]);
        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->setUser($student);

        $result = external_api::clean_returnvalue(
            get_sequence::execute_returns(), get_sequence::execute($agon->cmid, 1));
        $seq = json_decode($result['sequence'], true);
        $this->assertSame('S2', $seq['title']);
        $this->assertSame(['z', 'w'], $seq['options']);
        $this->assertArrayNotHasKey('blanks', $seq);

        // Out-of-range index is rejected.
        $this->expectException(\moodle_exception::class);
        get_sequence::execute($agon->cmid, 9);
    }
}
