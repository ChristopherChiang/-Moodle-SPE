<?php
require('../../config.php');
require_login();

$courseid = required_param('id', PARAM_INT); 
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

$PAGE->set_url('/mod/spe/index.php', ['id' => $courseid]);
$PAGE->set_title('Self & Peer Evaluation');
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading('Self & Peer Evaluation activities in this course');

echo $OUTPUT->footer();
