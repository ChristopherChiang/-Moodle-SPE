<?php

require('../../config.php');

$cmid   = required_param('id', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT); 

$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);

// Set capability 
$canmanage = has_capability('mod/spe:manage', $context);

if (!$userid) {
    $userid = $USER->id;
}
$target = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname, username', MUST_EXIST);

// Student to only view own, staff able to view all.
if (!$canmanage && (int)$userid !== (int)$USER->id) {
    print_error('nopermissions', 'error', '', 'view this submission');
}

// Activity record
$spe   = $DB->get_record('spe', ['id' => $cm->instance]); // OK if null
$aname = $spe ? format_string($spe->name) : format_string($cm->name);

// Set page
$PAGE->set_url('/mod/spe/submission.php', ['id' => $cm->id, 'userid' => $userid]);
$PAGE->set_title($aname);
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');
$PAGE->add_body_class('spe-compact spe-left');

// Instructor dashboard button
if ($canmanage) {
    $label = get_string_manager()->string_exists('instructordashboard', 'spe')
        ? get_string('instructordashboard', 'spe')
        : 'Instructor dashboard';
    $PAGE->set_button(
        $OUTPUT->single_button(
            new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]),
            $label,
            'get'
        )
    );
}

echo $OUTPUT->header();

// Title
echo html_writer::tag('h2', $aname, ['class' => 'mb-2']);


//Fetch submission, ratings, last modified time
$submission   = $DB->get_record('spe_submission', ['speid' => $cm->instance, 'userid' => $userid]);
$ratings      = $DB->get_records('spe_rating',    ['speid' => $cm->instance, 'raterid' => $userid], 'rateeid, id');
$lastmodified = $submission ? (int)($submission->timemodified ?: $submission->timecreated) : 0;

// Deadline and early detection
$timemsg  = '-';
$timehtml = $timemsg;

if ($timedue > 0) {
    if ($submission) {
        $delta   = $timedue - $lastmodified;
        $when    = format_time(abs($delta));
        $timemsg = ($delta >= 0)
            ? "Assignment was submitted {$when} early"
            : "Assignment was submitted {$when} late";

        // Green if early, red if late
        $timehtml = ($delta >= 0)
            ? html_writer::span($timemsg, '', ['style' => 'color:#036635;font-weight:600;'])
            : html_writer::span($timemsg, '', ['style' => 'color:#b42318;font-weight:600;']);
    } else {
        $remain  = $timedue - time();
        $timemsg = ($remain >= 0) ? 'Time remaining: ' . format_time($remain)
                                  : 'Closed ' . format_time(-$remain) . ' ago';
        $timehtml = s($timemsg);
    }
} else {
    $timehtml = s($timemsg);
}

// Submission badge
$hassubmission = !empty($submission);
$subhtml = $hassubmission
    ? html_writer::span('Submitted for grading', '', [
        'style' => 'padding:2px 8px;border-radius:10px;font-weight:600;'
      ])
    : 'No submission';

// Grading badge
$pubpref = json_decode(get_user_preferences('mod_spe_groupscore_' . $cm->id, '', $userid), true);
$graded  = is_array($pubpref) && (isset($pubpref['stars']) || isset($pubpref['percent']));
$gradehtml = $graded
    ? html_writer::span('Graded', '', [
        'style' => 'padding:2px 8px;border-radius:10px;font-weight:600;'
      ])
    : 'Not graded';

// Row background rules (green when applicable)
$subrowstyle   = $hassubmission ? 'background:#dff0d8;' : '';
$graderowstyle = $graded        ? 'background:#dff0d8;' : '';


// Submission status table
echo html_writer::tag('h3', 'Submission status', ['class' => 'mt-4']);

echo html_writer::start_tag('table', ['class' => 'generaltable', 'style' => 'width:auto;min-width:60%;']);

$tr = function(string $label, string $valuehtml, string $rowstyle = ''): string {
    return html_writer::tag('tr',
        html_writer::tag('th', $label, ['style' => 'width:240px;']) .
        html_writer::tag('td', $valuehtml),
        ['style' => $rowstyle]
    );
};

echo $tr('Submission status', $subhtml, $subrowstyle);
echo $tr('Grading status',   $gradehtml, $graderowstyle);

// Last modified row
$lm = $lastmodified ? userdate($lastmodified, get_string('strftimedatetime', 'langconfig')) : '-';
echo $tr('Last modified', s($lm));

// File submissions row
$pdfurl  = new moodle_url('/mod/spe/submission_pdf.php', ['id' => $cm->id, 'userid' => $userid]);
$pdflink = html_writer::link($pdfurl, 'Download submission PDF');
echo $tr('File submissions', $pdflink);

// Group score row after grading
if ($graded) {
    $stars   = isset($pubpref['stars'])   ? $pubpref['stars']   : null;
    $percent = isset($pubpref['percent']) ? $pubpref['percent'] : null;
    if ($stars !== null || $percent !== null) {
        $display = ($stars !== null ? $stars . ' / 5' : '');
        if ($percent !== null) {
            $display .= ($display ? ' ' : '');
        }
        echo $tr('Score', s($display));
    }
}

echo html_writer::end_tag('table');

// Reset button for instructors
if ($canmanage) {
    $reseturl = new moodle_url('/mod/spe/submission_reset.php', [
        'id' => $cm->id,
        'userid' => $userid,
        'sesskey' => sesskey()
    ]);

    echo html_writer::div(
        html_writer::link(
            $reseturl,
            '🧹 Reset this submission (allow resubmission)',
            [
                'class' => 'btn btn-danger',
                'style' => 'margin-top:14px',
                'onclick' => "return confirm('Reset this student\\'s SPE? This deletes their submission, ratings, and sentiment rows.');"
            ]
        )
    );
}

// Back link
$backcourse = new moodle_url('/course/view.php', ['id' => $course->id]);
$backact    = new moodle_url('/mod/spe/view.php',  ['id' => $cm->id]);

echo html_writer::div(
    html_writer::link($backact, '← Back to activity', [
        'class' => 'btn btn-secondary',
        'style' => 'margin-top:12px;margin-right:8px;'
    ]) .
    html_writer::link($backcourse, '← Back to course', [
        'class' => 'btn btn-secondary',
        'style' => 'margin-top:12px;'
    ])
);

echo $OUTPUT->footer();
