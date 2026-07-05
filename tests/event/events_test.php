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

namespace mod_agon\event;

/**
 * Tests for the mod_agon events.
 *
 * @package     mod_agon
 * @category    test
 * @covers      \mod_agon\event\course_module_viewed
 * @covers      \mod_agon\event\course_module_instance_list_viewed
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class events_test extends \advanced_testcase {
    public function test_course_module_viewed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $agon = $this->getDataGenerator()->create_module('agon', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('agon', $agon->id, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $event = course_module_viewed::create(['objectid' => $agon->id, 'context' => $context]);
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(course_module_viewed::class, $event);
        $this->assertSame('agon', $event->objecttable);
        $this->assertEquals($agon->id, $event->objectid);
        $this->assertSame('r', $event->crud);
        $this->assertEquals($context, $event->get_context());
    }

    public function test_course_module_instance_list_viewed(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        $event = course_module_instance_list_viewed::create(['context' => $context]);
        $sink = $this->redirectEvents();
        $event->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(course_module_instance_list_viewed::class, reset($events));
        $this->assertEquals($context, reset($events)->get_context());
    }
}
