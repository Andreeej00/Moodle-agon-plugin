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
 * Web service: extract text from an uploaded lecture file (PDF or PPTX).
 *
 * @package     mod_agon
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class extract_file extends external_api {
    use uses_agon_context;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id of the agon activity'),
            'filename' => new external_value(PARAM_FILE, 'Original file name (for the extension)'),
            'content' => new external_value(PARAM_RAW, 'The file bytes, base64-encoded'),
        ]);
    }

    /**
     * Extract and return the file's text.
     *
     * @param int $cmid Course module id.
     * @param string $filename File name.
     * @param string $content Base64 file bytes.
     * @return array {text: string}
     */
    public static function execute(int $cmid, string $filename, string $content): array {
        $params = self::validate_parameters(self::execute_parameters(),
            ['cmid' => $cmid, 'filename' => $filename, 'content' => $content]);
        self::require_cm($params['cmid'], 'mod/agon:manage');

        return ['text' => ai::extract_upload($params['filename'], $params['content'])];
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
