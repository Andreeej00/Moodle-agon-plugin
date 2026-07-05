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

/**
 * Tests for the Moodle callbacks in lib.php.
 *
 * @package     mod_agon
 * @category    test
 * @covers      ::agon_supports
 * @covers      ::agon_is_branded
 * @covers      ::agon_add_instance
 * @covers      ::agon_update_instance
 * @covers      ::agon_delete_instance
 * @covers      ::agon_extend_settings_navigation
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lib_test extends \advanced_testcase {
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/mod/agon/lib.php');
        $this->resetAfterTest();
    }

    public function test_supports(): void {
        $this->assertTrue(agon_supports(FEATURE_MOD_INTRO));
        // No gradebook export yet (plan §8) — declaring it would surface a
        // do-nothing Grade section in the settings form.
        $this->assertNull(agon_supports(FEATURE_GRADE_HAS_GRADE));
        $this->assertNull(agon_supports(FEATURE_BACKUP_MOODLE2));
        $this->assertNull(agon_supports(FEATURE_COMPLETION_TRACKS_VIEWS));
    }

    public function test_is_branded(): void {
        $this->assertTrue(agon_is_branded());
    }

    public function test_add_update_delete_instance(): void {
        global $DB;
        $course = $this->getDataGenerator()->create_course();

        $id = agon_add_instance((object)[
            'course' => $course->id,
            'name' => 'Week 1',
            'intro' => '',
            'introformat' => FORMAT_HTML,
            'gamecrossword' => 1,
            'gamequestion' => 1,
            'gamecoding' => 0,
        ]);
        $row = $DB->get_record('agon', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('Week 1', $row->name);
        $this->assertGreaterThan(0, (int)$row->timecreated);
        $this->assertEquals(0, $row->gamecoding);

        $this->assertTrue(agon_update_instance((object)[
            'instance' => $id,
            'course' => $course->id,
            'name' => 'Week 1 (renamed)',
            'intro' => '',
            'introformat' => FORMAT_HTML,
        ]));
        $row = $DB->get_record('agon', ['id' => $id], '*', MUST_EXIST);
        $this->assertSame('Week 1 (renamed)', $row->name);
        $this->assertGreaterThan(0, (int)$row->timemodified);

        $this->assertTrue(agon_delete_instance($id));
        $this->assertFalse($DB->record_exists('agon', ['id' => $id]));
        // Deleting a non-existent instance reports failure instead of pretending.
        $this->assertFalse(agon_delete_instance($id));
    }

    /**
     * The Question bank tab appears in the activity's settings navigation for
     * content managers only, exactly once, with a working cm fallback.
     */
    public function test_extend_settings_navigation_adds_bank_tab_for_managers(): void {
        $course = $this->getDataGenerator()->create_course();
        $agon = $this->getDataGenerator()->create_module('agon', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('agon', $agon->id, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $makenav = function(bool $withcm) use ($cm, $course, $context) {
            $page = new \moodle_page();
            if ($withcm) {
                $page->set_cm(\cm_info::create($cm), $course);
            } else {
                // Some nav passes have no cm on the page yet — only the context.
                $page->set_context($context);
            }
            $page->set_url(new \moodle_url('/mod/agon/view.php', ['id' => $cm->id]));
            // The real constructor builds the whole site navigation (too heavy and
            // cm-dependent for a unit test); the hook only ever calls get_page().
            return new class($page) extends \settings_navigation {
                /** @param \moodle_page $page The page to expose via get_page(). */
                public function __construct(\moodle_page $page) {
                    $this->page = $page;
                }
            };
        };

        // Teacher: the tab is added, pointing at bank.php.
        $this->setUser($teacher);
        $node = new \navigation_node('Agon');
        agon_extend_settings_navigation($makenav(true), $node);
        $tab = $node->get('agonbank');
        $this->assertNotEmpty($tab, 'Teachers should get the Question bank tab');
        $this->assertStringContainsString('/mod/agon/bank.php', $tab->action()->out(false));

        // A second nav pass must not duplicate the tab.
        agon_extend_settings_navigation($makenav(true), $node);
        $this->assertCount(1, $node->find_all_of_type(\navigation_node::TYPE_CUSTOM));

        // When the page has no cm yet, the module context resolves it.
        $node = new \navigation_node('Agon');
        agon_extend_settings_navigation($makenav(false), $node);
        $this->assertNotEmpty($node->get('agonbank'), 'Context fallback should still find the cm');

        // Student: no capability, no tab.
        $this->setUser($student);
        $this->assertFalse(has_capability('mod/agon:manage', $context));
        $node = new \navigation_node('Agon');
        agon_extend_settings_navigation($makenav(true), $node);
        $this->assertFalse($node->get('agonbank'), 'Students should not get the Question bank tab');

        // No node at all is a quiet no-op.
        $this->assertNull(agon_extend_settings_navigation($makenav(true), null));
    }
}
