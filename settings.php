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
 * Plugin administration pages are defined here.
 *
 * @package     mod_agon
 * @category    admin
 * @copyright   2026 Andrej Micic
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('mod_agon_settings', new lang_string('pluginname', 'mod_agon'));

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_heading('mod_agon/aihdr',
            new lang_string('aisettings', 'mod_agon'), ''));

        $settings->add(new admin_setting_configcheckbox('mod_agon/aienable',
            new lang_string('aienable', 'mod_agon'),
            new lang_string('aienable_desc', 'mod_agon'), 0));

        $settings->add(new admin_setting_configselect('mod_agon/aiprovider',
            new lang_string('aiprovider', 'mod_agon'),
            new lang_string('aiprovider_desc', 'mod_agon'), 'google',
            ['google' => 'Google (Gemini)', 'anthropic' => 'Claude', 'openai' => 'ChatGPT']));

        // The default key can also live in config.php as
        // $CFG->forced_plugin_settings['mod_agon']['aiapikey'] (a file-based secret).
        $settings->add(new admin_setting_configpasswordunmask('mod_agon/aiapikey',
            new lang_string('aiapikey', 'mod_agon'),
            new lang_string('aiapikey_desc', 'mod_agon'), ''));

        $settings->add(new admin_setting_configtext('mod_agon/aimodel',
            new lang_string('aimodel', 'mod_agon'),
            new lang_string('aimodel_desc', 'mod_agon'), 'gemini-2.5-flash', PARAM_RAW_TRIMMED));
    }
}
