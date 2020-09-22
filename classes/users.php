<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 *
 *
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @package   block_createuser
 * @copyright 21-09-20 Mfreak.nl | LdesignMedia.nl - Luuk Verhoeven
 * @author    Wishal Fakira
 **/

namespace block_createuser;

defined('MOODLE_INTERNAL') || die;

/**
 * Class create_users
 *
 * @copyright 16-09-20 Mfreak.nl | LdesignMedia.nl - Luuk Verhoeven
 * @author    Wishal Fakira
 */
class users {

    /**
     * @param $users
     */
    public static function create_task_form_wizard(array $users) : void {
        global $DB, $USER;
        if (empty($users)) {
            return;
        }
        $DB->insert_record('block_createuser', (object)[
            'usersdata' => serialize($users),
            'is_processed' => 0,
            'timecreated' => time(),
            'createdby' => $USER->id,

        ]);
    }

    /**
     * @param $user
     */
    protected static function create_single_user($user, int $createdby) : void {

        global $DB;
        try {
            $user->username = $user->email;
            $user->lang = 'nl';
            $user->id = user_create_user($user, false, false);

            $user = $DB->get_record('user', ['id' => $user->id]);
            $fieldid = get_config('block_createuser', 'profile_user_link');

            if (!empty($fieldid)) {
                helper::update_user_profile_value($user->id, $fieldid, $createdby);
            }

            // Sends email with password to user.
            setnew_password_and_mail($user);
            unset_user_preference('create_password', $user);
            set_user_preference('auth_forcepasswordchange', 1, $user);
            $courseids = helper::get_courseids_from_settings();

            // Enrol users to all courses.
            array_walk($courseids, 'static::enrol', ['user' => $user]);

        } catch (\Exception $exception) {
            mtrace('Error creating user: ' . $exception->getMessage());
        }

    }

    public static function unset_session() : void {
        global $SESSION;
        unset($SESSION->block_createuser);
    }

    /**
     * @param array $users
     * @param int   $createdby
     */
    public static function create_users(array $users, int $createdby) : void {
        array_map('static::create_single_user', $users, [$createdby]);
    }

    /**
     * @param int       $courseid
     * @param \stdClass $user
     *
     * @return void
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public static function enrol(int $courseid, $key, $userdata) : void {
        global $DB;

        $user = $userdata['user'];
        if (empty($user)) {
            return;
        }
        // Check if we need to enrol users for a new course.

        $enrol = enrol_get_plugin('manual');

        if ($enrol === null) {
            return;
        }

        $instance = $DB->get_record('enrol', [
            'courseid' => $courseid,
            'enrol' => 'manual',
        ], '*');

        if (empty($instance)) {
            return;
        }

        $now = time();
        $period = get_config('block_createuser', 'enrolment_duration');
        $timeend = $now + $period;
        $enrol->enrol_user($instance,
            $user->id,
            get_config('block_createuser', 'role'),
            $now,
            $timeend,
            ENROL_USER_ACTIVE,
            true
        );
    }

}
 