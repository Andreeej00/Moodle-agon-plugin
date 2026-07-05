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
 * Library of interface functions and constants.
 *
 * @package     mod_agon
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Return if the plugin supports $feature.
 *
 * Gradebook export is deliberately NOT declared yet: no grade is ever written
 * (scores live in agon_attempt and feed the course leaderboard), and declaring
 * FEATURE_GRADE_HAS_GRADE would surface a do-nothing "Grade" section in the
 * settings form. Declare it (and re-add the grade_* callbacks) when the
 * gradebook phase lands (plan §8).
 *
 * @param string $feature Constant representing the feature.
 * @return true | null True if the feature is supported, null otherwise.
 */
function agon_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        default:
            return null;
    }
}

/**
 * Declare the activity icon as branded: it renders in its own colours on the
 * course page, without the purpose-coloured background container.
 *
 * @return bool
 */
function agon_is_branded(): bool {
    return true;
}

/**
 * Saves a new instance of the mod_agon into the database.
 *
 * Given an object containing all the necessary data, (defined by the form
 * in mod_form.php) this function will create a new instance and return the id
 * number of the instance.
 *
 * @param object $moduleinstance An object from the form.
 * @param mod_agon_mod_form $mform The form.
 * @return int The id of the newly inserted record.
 */
function agon_add_instance($moduleinstance, $mform = null) {
    global $DB;

    $moduleinstance->timecreated = time();

    $id = $DB->insert_record('agon', $moduleinstance);

    return $id;
}

/**
 * Updates an instance of the mod_agon in the database.
 *
 * Given an object containing all the necessary data (defined in mod_form.php),
 * this function will update an existing instance with new data.
 *
 * @param object $moduleinstance An object from the form in mod_form.php.
 * @param mod_agon_mod_form $mform The form.
 * @return bool True if successful, false otherwise.
 */
function agon_update_instance($moduleinstance, $mform = null) {
    global $DB;

    $moduleinstance->timemodified = time();
    $moduleinstance->id = $moduleinstance->instance;

    return $DB->update_record('agon', $moduleinstance);
}

/**
 * Removes an instance of the mod_agon from the database.
 *
 * @param int $id Id of the module instance.
 * @return bool True if successful, false on failure.
 */
function agon_delete_instance($id) {
    global $DB;

    $exists = $DB->get_record('agon', ['id' => $id]);
    if (!$exists) {
        return false;
    }

    $DB->delete_records('agon', ['id' => $id]);

    return true;
}

/**
 * Extends the settings navigation with the mod_agon settings.
 *
 * This function is called when the context for the page is a mod_agon module.
 * This is not called by AJAX so it is safe to rely on the $PAGE.
 *
 * @param settings_navigation $settingsnav {@see settings_navigation}
 * @param navigation_node $agonnode {@see navigation_node}
 */
function agon_extend_settings_navigation($settingsnav, $agonnode = null) {
    if (!$agonnode || $agonnode->get('agonbank')) {
        // No node to extend, or the tab was already added on an earlier nav pass.
        return;
    }
    // The cm is normally on the page; on some nav passes it is not yet set, so
    // fall back to the module context (this hook only fires on agon module pages).
    // NOTE: read the magic properties into variables — empty()/isset() on them is
    // always true-empty because moodle_page has __get() but no __isset().
    $page = $settingsnav->get_page();
    $cm = $page->cm;
    if (!$cm) {
        $context = $page->context;
        if ($context && $context->contextlevel == CONTEXT_MODULE) {
            $cm = get_coursemodule_from_id('agon', $context->instanceid, 0, false, IGNORE_MISSING);
        }
    }
    if (empty($cm) || $cm->modname !== 'agon') {
        return;
    }
    $context = context_module::instance($cm->id, IGNORE_MISSING);
    // Only content managers (teachers/assistants) get the authoring screen.
    if (!$context || !has_capability('mod/agon:manage', $context)) {
        return;
    }
    // TYPE_CUSTOM so it surfaces as a tab in the activity's secondary navigation.
    $agonnode->add(
        get_string('questionbank', 'mod_agon'),
        new moodle_url('/mod/agon/bank.php', ['id' => $cm->id]),
        navigation_node::TYPE_CUSTOM,
        null,
        'agonbank',
        new pix_icon('i/questions', '')
    );
}
