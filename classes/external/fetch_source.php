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
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_agon\local\ai;

/**
 * Web service: fetch and extract text from a lecture-material link.
 *
 * @package     mod_agon
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fetch_source extends external_api {
    use uses_agon_context;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the agon activity'),
            'url' => new external_value(PARAM_RAW_TRIMMED, 'Google Docs/Slides link to read'),
        ]);
    }

    /**
     * Fetch the link and return its extracted text.
     *
     * @param int $cmid Course module id.
     * @param string $url Link to read.
     * @return array {text: string}
     */
    public static function execute(int $cmid, string $url): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'url' => $url]);
        self::require_cm($params['cmid'], 'mod/agon:manage');

        return ['text' => ai::fetch_source($params['url'])];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'text' => new external_value(PARAM_RAW, 'The extracted text'),
        ]);
    }
}
