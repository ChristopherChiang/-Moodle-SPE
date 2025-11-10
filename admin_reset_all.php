<?php

require('../../config.php');

$cmid    = required_param('id', PARAM_INT);    
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_login($course, false, $cm);

// Admins only
$sysctx = context_system::instance();
if (!is_siteadmin() && !has_capability('moodle/site:config', $sysctx)) {
    print_error('nopermissions', 'error', '', 'reset all SPE data');
}

$context = context_module::instance($cm->id);

// Set page
$PAGE->set_url('/mod/spe/admin_reset_all.php', ['id' => $cm->id]);
$PAGE->set_title('Admin — Reset All SPE Data');
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading('Admin — Reset All SPE Data', 2);

// Confirmation step
if (!$confirm) {
    $confirmurl = new moodle_url('/mod/spe/admin_reset_all.php', [
        'id'      => $cm->id,
        'confirm' => 1,
        'sesskey' => sesskey()
    ]);
    $cancelurl  = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);

    echo $OUTPUT->confirm(
        '⚠️ This will delete <strong>all</strong> submissions, ratings, sentiments, group scores and published grades for this SPE. Continue?',
        $confirmurl,
        $cancelurl
    );
    echo $OUTPUT->footer();
    exit;
}

require_sesskey();

// ---------- Execute reset ----------------

// Set base tables
$basetables = [
    'spe_submission',
    'spe_rating',
    'spe_sentiment',
    'spe_group_score',
];

// Optional tables
$optionaltables = [
    'spe_disparity',
    'spe_reflection',
];

// Delete per-table rows 
$manager = $DB->get_manager();

foreach (array_merge($basetables, $optionaltables) as $table) {
    if ($manager->table_exists($table)) {
        $DB->delete_records($table, ['speid' => $cm->instance]);
    }
}

// Clear published Graded
$prefname = 'mod_spe_groupscore_' . $cm->id;
$DB->delete_records('user_preferences', ['name' => $prefname]);

// Clear gradebook grades
$DB->delete_records('config_plugins', [
    'plugin' => 'mod_spe',
    'name'   => 'gradingdone_cmid_' . $cm->id
]);
$DB->delete_records('config_plugins', [
    'plugin' => 'mod_spe',
    'name'   => 'gradingdonetime_cmid_' . $cm->id
]);

// Finished
\core\notification::success('All SPE data, including published grades, has been wiped for this activity.');

$backurl = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
echo html_writer::div(
    html_writer::link($backurl, '← Return to Instructor Dashboard', [
        'class' => 'btn btn-primary',
        'style' => 'margin-top:15px'
    ])
);

echo $OUTPUT->footer();
