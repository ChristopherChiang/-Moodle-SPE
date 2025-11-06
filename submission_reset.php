<?php

require('../../config.php');

$cmid   = required_param('id', PARAM_INT);
$userid = required_param('userid', PARAM_INT);

$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);

// Set capability
require_capability('mod/spe:manage', $context);
require_sesskey();

// Remove student submission record.
$DB->delete_records('spe_submission', [
    'speid' => $cm->instance,
    'userid' => $userid
]);

// Remove ratings
$DB->delete_records('spe_rating', [
    'speid'   => $cm->instance,
    'raterid' => $userid
]);

// Remove sentiments
$DB->delete_records('spe_sentiment', [
    'speid'   => $cm->instance,
    'raterid' => $userid
]);

// Remove disparity
$manager = $DB->get_manager();
if ($manager->table_exists('spe_disparity')) {
    $DB->delete_records('spe_disparity', [
        'speid'   => $cm->instance,
        'raterid' => $userid
    ]);
}

// Remove group scores
if ($manager->table_exists('spe_group_score')) {
    $DB->delete_records_select(
        'spe_group_score',
        'speid = :speid AND (userid = :uid OR studentid = :uid)',
        ['speid' => $cm->instance, 'uid' => $userid]
    );
}

// Clear group score
$prefname = 'mod_spe_groupscore_' . $cm->id;
unset_user_preference($prefname, $userid);

// Redirect to instructor page
redirect(new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]), 'Submission reset successfully.', 1);
