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
 * Coverage information for mod_agon.
 *
 * The measured surface is the engine + web services (classes/) and the Moodle
 * callbacks (lib.php). Page scripts (view.php, bank.php, index.php), the form,
 * settings and upgrade steps are exercised through Behat / the live site, not
 * unit-covered.
 *
 * @package     mod_agon
 * @category    test
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

return new class extends phpunit_coverage_info {
    /** @var array The list of folders relative to the plugin root to include in coverage generation. */
    protected $includelistfolders = ['classes'];

    /** @var array The list of files relative to the plugin root to include in coverage generation. */
    protected $includelistfiles = ['lib.php'];
};
