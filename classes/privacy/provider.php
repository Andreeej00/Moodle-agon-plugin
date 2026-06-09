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
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\writer;

/**
 * Privacy API implementation for mod_agon.
 *
 * Stores one attempt row per student per activity (scores, timing, progress).
 *
 * @package     mod_agon
 * @category    privacy
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Describe the personal data stored by the plugin.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('agon_attempt', [
            'userid' => 'privacy:metadata:agon_attempt:userid',
            'timestart' => 'privacy:metadata:agon_attempt:timestart',
            'timefinish' => 'privacy:metadata:agon_attempt:timefinish',
            'state' => 'privacy:metadata:agon_attempt:state',
            'score' => 'privacy:metadata:agon_attempt:score',
            'scorecrossword' => 'privacy:metadata:agon_attempt:scorecrossword',
            'scorequestion' => 'privacy:metadata:agon_attempt:scorequestion',
            'scorecoding' => 'privacy:metadata:agon_attempt:scorecoding',
        ], 'privacy:metadata:agon_attempt');
        return $collection;
    }

    /**
     * Module contexts in which the user has an attempt.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {course_modules} cm ON cm.id = ctx.instanceid AND ctx.contextlevel = :contextlevel
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                  JOIN {agon} ag ON ag.id = cm.instance
                  JOIN {agon_attempt} a ON a.agonid = ag.id
                 WHERE a.userid = :userid";
        $contextlist->add_from_sql($sql, [
            'contextlevel' => CONTEXT_MODULE,
            'modname' => 'agon',
            'userid' => $userid,
        ]);
        return $contextlist;
    }

    /**
     * Users who have an attempt in the given context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $sql = "SELECT a.userid
                  FROM {agon_attempt} a
                  JOIN {course_modules} cm ON cm.instance = a.agonid
                  JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                 WHERE cm.id = :cmid";
        $userlist->add_from_sql('userid', $sql, ['modname' => 'agon', 'cmid' => $context->instanceid]);
    }

    /**
     * Export the user's attempts for the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('agon', $context->instanceid);
            if (!$cm) {
                continue;
            }
            $attempt = $DB->get_record('agon_attempt', ['agonid' => $cm->instance, 'userid' => $userid]);
            if (!$attempt) {
                continue;
            }
            $data = (object)[
                'state' => $attempt->state,
                'score' => $attempt->score,
                'scorecrossword' => $attempt->scorecrossword,
                'scorequestion' => $attempt->scorequestion,
                'scorecoding' => $attempt->scorecoding,
                'timestart' => $attempt->timestart ? transform::datetime($attempt->timestart) : '',
                'timefinish' => $attempt->timefinish ? transform::datetime($attempt->timefinish) : '',
            ];
            writer::with_context($context)->export_data([], $data);
        }
    }

    /**
     * Delete all attempts for all users in a context.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('agon', $context->instanceid);
        if ($cm) {
            $DB->delete_records('agon_attempt', ['agonid' => $cm->instance]);
        }
    }

    /**
     * Delete the given user's attempts in the approved contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_module) {
                continue;
            }
            $cm = get_coursemodule_from_id('agon', $context->instanceid);
            if ($cm) {
                $DB->delete_records('agon_attempt', ['agonid' => $cm->instance, 'userid' => $userid]);
            }
        }
    }

    /**
     * Delete attempts for the given users in a context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_module) {
            return;
        }
        $cm = get_coursemodule_from_id('agon', $context->instanceid);
        if (!$cm) {
            return;
        }
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params = array_merge(['agonid' => $cm->instance], $inparams);
        $DB->delete_records_select('agon_attempt', "agonid = :agonid AND userid $insql", $params);
    }
}
