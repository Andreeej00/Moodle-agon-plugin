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
 * Plugin upgrade steps are defined here.
 *
 * @package     mod_agon
 * @category    upgrade
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/upgradelib.php');

/**
 * Execute mod_agon upgrade from the given old version.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_agon_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026060800) {
        $table = new xmldb_table('agon');
        $fields = [
            new xmldb_field('gamecrossword', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1', 'introformat'),
            new xmldb_field('gamequestion', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1', 'gamecrossword'),
            new xmldb_field('gamecoding', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1', 'gamequestion'),
            new xmldb_field('contentcrossword', XMLDB_TYPE_TEXT, null, null, null, null, null, 'gamecoding'),
            new xmldb_field('contentquestion', XMLDB_TYPE_TEXT, null, null, null, null, null, 'contentcrossword'),
            new xmldb_field('contentcoding', XMLDB_TYPE_TEXT, null, null, null, null, null, 'contentquestion'),
        ];
        foreach ($fields as $field) {
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }
        upgrade_mod_savepoint(true, 2026060800, 'agon');
    }

    if ($oldversion < 2026060900) {
        // Phase 2: per-student attempt tracking + server-side scores.
        $table = new xmldb_table('agon_attempt');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('agonid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('state', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'inprogress');
            $table->add_field('timestart', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timefinish', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('score', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('scorecrossword', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('scorequestion', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('scorecoding', XMLDB_TYPE_NUMBER, '10, 5', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('fk_agon', XMLDB_KEY_FOREIGN, ['agonid'], 'agon', ['id']);
            $table->add_key('fk_user', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);
            $table->add_key('agonid-userid', XMLDB_KEY_UNIQUE, ['agonid', 'userid']);
            $dbman->create_table($table);
        }
        upgrade_mod_savepoint(true, 2026060900, 'agon');
    }

    return true;
}
