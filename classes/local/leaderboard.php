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

namespace mod_agon\local;

/**
 * Leaderboard + monitor aggregation.
 *
 * The course leaderboard sums each student's attempt scores across every agon
 * instance in the course (cumulative across games and weeks, per plan.md §5).
 *
 * @package     mod_agon
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class leaderboard {
    /**
     * Ranked course-wide leaderboard: per student, the sum of their attempt
     * scores across all agon instances in the course.
     *
     * @param int $courseid
     * @param int $highlightuserid Mark this user's row with me => true (0 = none).
     * @param int $limit Maximum rows to return.
     * @return array<int, array{name: string, pts: float, me: bool}>
     */
    public static function course_totals(int $courseid, int $highlightuserid = 0, int $limit = 20): array {
        global $DB;

        $sql = "SELECT a.userid, SUM(a.score) AS pts
                  FROM {agon_attempt} a
                  JOIN {agon} ag ON ag.id = a.agonid
                 WHERE ag.course = :courseid
              GROUP BY a.userid
                HAVING SUM(a.score) > 0
              ORDER BY pts DESC, a.userid ASC";
        $rows = $DB->get_records_sql($sql, ['courseid' => $courseid], 0, $limit);
        if (!$rows) {
            return [];
        }

        $users = $DB->get_records_list('user', 'id', array_keys($rows));
        $out = [];
        foreach ($rows as $userid => $r) {
            $user = $users[$userid] ?? null;
            $out[] = [
                'name' => $user ? fullname($user) : get_string('unknownuser'),
                'pts' => round((float)$r->pts, 2),
                'me' => $highlightuserid && (int)$userid === $highlightuserid,
            ];
        }
        return $out;
    }

    /**
     * Per-student attempt rows for one instance (the teacher monitor table).
     *
     * @param int $agonid
     * @return array<int, array{name: string, crossword: float, question: float, coding: float, done: bool}>
     */
    public static function attempts(int $agonid): array {
        global $DB;

        $rows = $DB->get_records('agon_attempt', ['agonid' => $agonid], 'score DESC, userid ASC');
        if (!$rows) {
            return [];
        }

        $userids = [];
        foreach ($rows as $r) {
            $userids[$r->userid] = true;
        }
        $users = $DB->get_records_list('user', 'id', array_keys($userids));

        $out = [];
        foreach ($rows as $r) {
            $user = $users[$r->userid] ?? null;
            $out[] = [
                'name' => $user ? fullname($user) : get_string('unknownuser'),
                'crossword' => round((float)$r->scorecrossword, 2),
                'question' => round((float)$r->scorequestion, 2),
                'coding' => round((float)$r->scorecoding, 2),
                'done' => $r->state === attempt::STATE_FINISHED,
            ];
        }
        return $out;
    }
}
