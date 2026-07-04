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
 * @param string $feature Constant representing the feature.
 * @return true | null True if the feature is supported, null otherwise.
 */
function agon_supports($feature) {
    switch ($feature) {
        case FEATURE_GRADE_HAS_GRADE:
            return true;
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
 * Is a given scale used by the instance of mod_agon?
 *
 * This function returns if a scale is being used by one mod_agon
 * if it has support for grading and scales.
 *
 * @param int $moduleinstanceid ID of an instance of this module.
 * @param int $scaleid ID of the scale.
 * @return bool True if the scale is used by the given mod_agon instance.
 */
function agon_scale_used($moduleinstanceid, $scaleid) {
    // Scales cannot be selected yet: the agon table has no grade column until
    // the gradebook step lands (plan §8 step 6), so no instance can use one.
    return false;
}

/**
 * Checks if scale is being used by any instance of mod_agon.
 *
 * This is used to find out if scale used anywhere.
 *
 * @param int $scaleid ID of the scale.
 * @return bool True if the scale is used by any mod_agon instance.
 */
function agon_scale_used_anywhere($scaleid) {
    // See agon_scale_used(): no grade column yet, so no scale can be in use.
    return false;
}

/**
 * Creates or updates grade item for the given mod_agon instance.
 *
 * Needed by {@see grade_update_mod_grades()}.
 *
 * @param stdClass $moduleinstance Instance object with extra cmidnumber and modname property.
 * @param bool $reset Reset grades in the gradebook.
 * @return void.
 */
function agon_grade_item_update($moduleinstance, $reset = false) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    // The agon table has no grade column until plan §8 step 6 lands.
    $grade = $moduleinstance->grade ?? 0;

    $item = [];
    $item['itemname'] = clean_param($moduleinstance->name, PARAM_NOTAGS);
    $item['gradetype'] = GRADE_TYPE_VALUE;

    if ($grade > 0) {
        $item['gradetype'] = GRADE_TYPE_VALUE;
        $item['grademax']  = $grade;
        $item['grademin']  = 0;
    } else if ($grade < 0) {
        $item['gradetype'] = GRADE_TYPE_SCALE;
        $item['scaleid']   = -$grade;
    } else {
        $item['gradetype'] = GRADE_TYPE_NONE;
    }
    if ($reset) {
        $item['reset'] = true;
    }

    grade_update('/mod/agon', $moduleinstance->course, 'mod', 'mod_agon', $moduleinstance->id, 0, null, $item);
}

/**
 * Delete grade item for given mod_agon instance.
 *
 * @param stdClass $moduleinstance Instance object.
 * @return grade_item.
 */
function agon_grade_item_delete($moduleinstance) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    return grade_update(
        '/mod/agon',
        $moduleinstance->course,
        'mod',
        'agon',
        $moduleinstance->id,
        0,
        null,
        ['deleted' => 1]
    );
}

/**
 * Update mod_agon grades in the gradebook.
 *
 * Needed by {@see grade_update_mod_grades()}.
 *
 * @param stdClass $moduleinstance Instance object with extra cmidnumber and modname property.
 * @param int $userid Update grade of specific user only, 0 means all participants.
 */
function agon_update_grades($moduleinstance, $userid = 0) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/gradelib.php');

    // Populate array of grade objects indexed by userid.
    $grades = [];
    grade_update('/mod/agon', $moduleinstance->course, 'mod', 'mod_agon', $moduleinstance->id, 0, $grades);
}

/**
 * Returns the lists of all browsable file areas within the given module context.
 *
 * The file area 'intro' for the activity introduction field is added automatically
 * by {@see file_browser::get_file_info_context_module()}.
 *
 * @package     mod_agon
 * @category    files
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @return string[].
 */
function agon_get_file_areas($course, $cm, $context) {
    return [];
}

/**
 * File browsing support for mod_agon file areas.
 *
 * @package     mod_agon
 * @category    files
 *
 * @param file_browser $browser
 * @param array $areas
 * @param stdClass $course
 * @param stdClass $cm
 * @param stdClass $context
 * @param string $filearea
 * @param int $itemid
 * @param string $filepath
 * @param string $filename
 * @return file_info Instance or null if not found.
 */
function agon_get_file_info($browser, $areas, $course, $cm, $context, $filearea, $itemid, $filepath, $filename) {
    return null;
}

/**
 * Serves the files from the mod_agon file areas.
 *
 * @package     mod_agon
 * @category    files
 *
 * @param stdClass $course The course object.
 * @param stdClass $cm The course module object.
 * @param stdClass $context The mod_agon's context.
 * @param string $filearea The name of the file area.
 * @param array $args Extra arguments (itemid, path).
 * @param bool $forcedownload Whether or not force download.
 * @param array $options Additional options affecting the file serving.
 */
function agon_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, $options = []) {
    global $DB, $CFG;

    if ($context->contextlevel != CONTEXT_MODULE) {
        send_file_not_found();
    }

    require_login($course, true, $cm);
    send_file_not_found();
}

/**
 * Extends the global navigation tree by adding mod_agon nodes if there is a relevant content.
 *
 * This can be called by an AJAX request so do not rely on $PAGE as it might not be set up properly.
 *
 * @param navigation_node $agonnode An object representing the navigation tree node.
 * @param stdClass $course
 * @param stdClass $module
 * @param cm_info $cm
 */
function agon_extend_navigation($agonnode, $course, $module, $cm) {
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
    $page = $settingsnav->get_page();
    $cm = $page->cm;
    if (!$cm && !empty($page->context) && $page->context->contextlevel == CONTEXT_MODULE) {
        $cm = get_coursemodule_from_id('agon', $page->context->instanceid, 0, false, IGNORE_MISSING);
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
