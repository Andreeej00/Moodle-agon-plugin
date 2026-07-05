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

namespace mod_agon\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use mod_agon\local\attempt;

/**
 * Tests for the mod_agon privacy provider.
 *
 * @package     mod_agon
 * @category    test
 * @covers      \mod_agon\privacy\provider
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_test extends \advanced_testcase {
    /** @var \stdClass */
    private $agon;
    /** @var \context_module */
    private $context;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->agon = $this->getDataGenerator()->create_module('agon', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('agon', $this->agon->id);
        $this->context = \context_module::instance($cm->id);
    }

    /**
     * Record a finished attempt with a given score.
     *
     * @param int $userid
     * @param float $score
     */
    private function record(int $userid, float $score): void {
        global $DB;
        $att = attempt::start($this->agon->id, $userid);
        $att->score = $score;
        $att->scorequestion = $score;
        $att->state = attempt::STATE_FINISHED;
        $DB->update_record('agon_attempt', $att);
    }

    public function test_metadata_describes_the_attempt_table(): void {
        $collection = provider::get_metadata(new collection('mod_agon'));
        $this->assertNotEmpty($collection->get_collection());
    }

    public function test_contexts_and_users(): void {
        $u1 = $this->getDataGenerator()->create_user();
        $u2 = $this->getDataGenerator()->create_user();
        $this->record($u1->id, 1.5);
        $this->record($u2->id, 2.0);

        $this->assertEqualsCanonicalizing(
            [$this->context->id],
            provider::get_contexts_for_userid($u1->id)->get_contextids());

        $userlist = new userlist($this->context, 'mod_agon');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$u1->id, $u2->id], $userlist->get_userids());
    }

    public function test_export_user_data(): void {
        $user = $this->getDataGenerator()->create_user();
        $this->record($user->id, 1.5);

        provider::export_user_data(new approved_contextlist($user, 'mod_agon', [$this->context->id]));
        $data = writer::with_context($this->context)->get_data([]);
        $this->assertSame('finished', $data->state);
        $this->assertEquals(1.5, $data->score);
    }

    public function test_delete_for_user_and_for_all(): void {
        global $DB;
        $u1 = $this->getDataGenerator()->create_user();
        $u2 = $this->getDataGenerator()->create_user();
        $this->record($u1->id, 1.5);
        $this->record($u2->id, 2.0);

        // Delete just u1.
        provider::delete_data_for_user(new approved_contextlist($u1, 'mod_agon', [$this->context->id]));
        $this->assertFalse($DB->record_exists('agon_attempt', ['agonid' => $this->agon->id, 'userid' => $u1->id]));
        $this->assertTrue($DB->record_exists('agon_attempt', ['agonid' => $this->agon->id, 'userid' => $u2->id]));

        // Delete everyone left in the context.
        provider::delete_data_for_all_users_in_context($this->context);
        $this->assertFalse($DB->record_exists('agon_attempt', ['agonid' => $this->agon->id]));
    }

    public function test_non_module_contexts_are_ignored(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $this->record($user->id, 1.0);
        $coursecontext = \context_course::instance($this->agon->course);

        // Userlist collection outside a module context stays empty.
        $userlist = new userlist($coursecontext, 'mod_agon');
        provider::get_users_in_context($userlist);
        $this->assertSame([], $userlist->get_userids());

        // Deletes scoped to a non-module context must not touch attempts.
        provider::delete_data_for_all_users_in_context($coursecontext);
        provider::delete_data_for_user(new approved_contextlist($user, 'mod_agon', [$coursecontext->id]));
        provider::delete_data_for_users(new approved_userlist($coursecontext, 'mod_agon', [$user->id]));
        $this->assertTrue($DB->record_exists('agon_attempt', ['agonid' => $this->agon->id, 'userid' => $user->id]));
    }

    public function test_delete_for_users_list(): void {
        global $DB;
        $u1 = $this->getDataGenerator()->create_user();
        $u2 = $this->getDataGenerator()->create_user();
        $this->record($u1->id, 1.5);
        $this->record($u2->id, 2.0);

        $userlist = new approved_userlist($this->context, 'mod_agon', [$u1->id]);
        provider::delete_data_for_users($userlist);
        $this->assertFalse($DB->record_exists('agon_attempt', ['agonid' => $this->agon->id, 'userid' => $u1->id]));
        $this->assertTrue($DB->record_exists('agon_attempt', ['agonid' => $this->agon->id, 'userid' => $u2->id]));
    }
}
