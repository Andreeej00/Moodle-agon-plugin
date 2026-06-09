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

use core_external\external_single_structure;
use core_external\external_multiple_structure;
use core_external\external_value;

/**
 * Shared behaviour for the agon attempt web services.
 *
 * @package     mod_agon
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait returns_attempt_summary {
    /**
     * The return structure shared by start/submit/finish: an attempt summary.
     *
     * @return external_single_structure
     */
    protected static function attempt_summary_structure(): external_single_structure {
        return new external_single_structure([
            'attemptid' => new external_value(PARAM_INT, 'Attempt id'),
            'state' => new external_value(PARAM_ALPHA, 'Attempt state: inprogress or finished'),
            'timestart' => new external_value(PARAM_INT, 'Server start time (unix)'),
            'timefinish' => new external_value(PARAM_INT, 'Finish time (unix); 0 if not finished'),
            'scorecrossword' => new external_value(PARAM_FLOAT, 'Crossword score'),
            'scorequestion' => new external_value(PARAM_FLOAT, 'Question score'),
            'scorecoding' => new external_value(PARAM_FLOAT, 'Coding score'),
            'score' => new external_value(PARAM_FLOAT, 'Total score'),
            'submittedgames' => new external_multiple_structure(
                new external_value(PARAM_ALPHA, 'A submitted game key'),
                'Games already submitted in this attempt'
            ),
            'feedback' => new external_value(
                PARAM_RAW,
                'JSON of the just-submitted game\'s revealed answers + explanation',
                VALUE_OPTIONAL
            ),
        ]);
    }
}
