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

/**
 * Context resolution + capability checks shared by the agon web services.
 *
 * @package     mod_agon
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait uses_agon_context {
    /**
     * Resolve the activity from a course module id and require a capability.
     *
     * @param int $cmid Course module id of the agon activity.
     * @param string $capability The capability the caller must hold in the module context.
     * @return \cm_info The course module (its ->instance is the agon id).
     */
    protected static function require_cm(int $cmid, string $capability): \cm_info {
        [, $cm] = get_course_and_cm_from_cmid($cmid, 'agon');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability($capability, $context);
        return $cm;
    }

    /**
     * Resolve the activity and require play access.
     *
     * @param int $cmid Course module id of the agon activity.
     * @return \cm_info The course module (its ->instance is the agon id).
     */
    protected static function setup_play_context(int $cmid): \cm_info {
        return self::require_cm($cmid, 'mod/agon:play');
    }
}
