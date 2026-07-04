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

/**
 * Web service function definitions for mod_agon.
 *
 * @package     mod_agon
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_agon_start_attempt' => [
        'classname' => 'mod_agon\external\start_attempt',
        'methodname' => 'execute',
        'description' => 'Start or resume the attempt for the current user.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/agon:play',
    ],
    'mod_agon_submit_game' => [
        'classname' => 'mod_agon\external\submit_game',
        'methodname' => 'execute',
        'description' => 'Grade one game submission server-side and return the updated attempt.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/agon:play',
    ],
    'mod_agon_finish_attempt' => [
        'classname' => 'mod_agon\external\finish_attempt',
        'methodname' => 'execute',
        'description' => 'Finish the attempt for the current user.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/agon:play',
    ],
    'mod_agon_get_hint' => [
        'classname' => 'mod_agon\external\get_hint',
        'methodname' => 'execute',
        'description' => 'Spend the attempt\'s one hint for a game.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/agon:play',
    ],
    'mod_agon_get_sequence' => [
        'classname' => 'mod_agon\external\get_sequence',
        'methodname' => 'execute',
        'description' => 'Fetch one coding sequence (lazy-loaded, no answers).',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/agon:play',
    ],
    'mod_agon_get_leaderboard' => [
        'classname' => 'mod_agon\external\get_leaderboard',
        'methodname' => 'execute',
        'description' => 'Return the cumulative course leaderboard.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/agon:viewleaderboard',
    ],
    'mod_agon_save_content' => [
        'classname' => 'mod_agon\external\save_content',
        'methodname' => 'execute',
        'description' => 'Save one game\'s content JSON (teacher authoring).',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/agon:manage',
    ],
    'mod_agon_fetch_source' => [
        'classname' => 'mod_agon\external\fetch_source',
        'methodname' => 'execute',
        'description' => 'Fetch and extract text from a lecture-material link (e.g. Google Docs/Slides).',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/agon:manage',
    ],
    'mod_agon_ai_prompt' => [
        'classname' => 'mod_agon\external\ai_prompt',
        'methodname' => 'execute',
        'description' => 'Build the AI prompt for a game (for the copy-into-any-AI helper).',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/agon:manage',
    ],
    'mod_agon_ai_generate' => [
        'classname' => 'mod_agon\external\ai_generate',
        'methodname' => 'execute',
        'description' => 'Generate a game\'s content JSON from lecture material using an AI provider.',
        'type' => 'write',
        'ajax' => true,
        'capabilities' => 'mod/agon:manage',
    ],
    'mod_agon_extract_file' => [
        'classname' => 'mod_agon\external\extract_file',
        'methodname' => 'execute',
        'description' => 'Extract text from an uploaded lecture file (PDF or PPTX).',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'mod/agon:manage',
    ],
];
