<?php

require('../../config.php');

$cmid   = required_param('id', PARAM_INT);
$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/spe:manage', $context);

$hidekey = 'hide_after_run_ts_' . $cm->id;
$hideafterts = (int) get_config('mod_spe', $hidekey);

// Set page
$PAGE->set_url('/mod/spe/instructor.php', ['id' => $cm->id]);
$PAGE->set_title('SPE — Instructor Panel');
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');
$PAGE->add_body_class('spe-compact spe-left');

// Header buttons
$btns = [];

// Group Approve button
$btns[] = $OUTPUT->single_button(
    new moodle_url('/mod/spe/group_approve.php', ['id' => $cm->id]),'Approve Group Scores','get');

// Reset All button for admin only
$sysctx = context_system::instance();
if (is_siteadmin() || has_capability('moodle/site:config', $sysctx)) 
{
    $btns[] = $OUTPUT->single_button(
        new moodle_url('/mod/spe/admin_reset_all.php', ['id' => $cm->id]),'Admin: Reset ALL','get');
}

// Apply header buttons (Grade book appears below)
$PAGE->set_button(implode(' ', $btns));

// Helper for name formatting
$mkname = function(string $first = '', string $last = ''): string {
    return format_string(trim($first . ' ' . $last));
};

// Output header
echo $OUTPUT->header();
echo $OUTPUT->heading('SPE — Instructor Management');

// Link shortcuts
$links = 
[
    html_writer::link(
        new moodle_url('/mod/spe/analysis_report.php', ['id' => $cm->id]),
        'Open Sentiment Analysis Report'
    ),
    html_writer::link(
        new moodle_url('/mod/spe/view.php', ['id' => $cm->id]),
        'Back to activity'
    ),
];
echo html_writer::alist($links, ['class' => 'list-unstyled mb-3']);

// Controls
$runall = optional_param('runall', 0, PARAM_BOOL); // global run analyzer after queueing?

// Pull all students with submissions
$allstudents = $DB->get_records_sql("
    SELECT u.id, u.firstname, u.lastname, u.username, s.id AS subid,
           s.timecreated, s.timemodified
      FROM {user} u
      JOIN {spe_submission} s ON s.userid = u.id
     WHERE s.speid = :speid
  ORDER BY u.lastname, u.firstname
", ['speid' => $cm->instance]);

// Process "Run Analysis" request
if ($runall && confirm_sesskey()) 
{
    if ($allstudents) 
    {
        foreach ($allstudents as $u) 
        {
            $reflectiontext = '';
            if ($sub = $DB->get_record('spe_submission', ['speid' => $cm->instance, 'userid' => $u->id])) 
            {
                if (!empty(trim($sub->reflection))) 
                {
                    $reflectiontext = trim($sub->reflection);
                }
            }
            if ($reflectiontext === '' && $DB->get_manager()->table_exists('spe_reflection')) 
            {
                $refrec = $DB->get_record('spe_reflection', ['speid' => $cm->instance, 'userid' => $u->id]);
                if ($refrec && !empty(trim($refrec->reflection))) 
                {
                    $reflectiontext = trim($refrec->reflection);
                }
            }
            if ($reflectiontext !== '') 
            {
                $exists = $DB->record_exists('spe_sentiment', 
                [
                    'speid'   => $cm->instance,
                    'raterid' => $u->id,
                    'rateeid' => $u->id,
                    'type'    => 'reflection',
                    'text'    => $reflectiontext
                ]);
                if (!$exists) 
                {
                    $DB->insert_record('spe_sentiment', (object)
                    [
                        'speid'       => $cm->instance,
                        'raterid'     => $u->id,
                        'rateeid'     => $u->id,
                        'type'        => 'reflection',
                        'text'        => $reflectiontext,
                        'status'      => 'pending',
                        'timecreated' => time()
                    ]);
                }
            }

            // Peer comments this user wrote
            $ratings = $DB->get_records('spe_rating', 
            [
                'speid'   => $cm->instance,
                'raterid' => $u->id
            ]);
            foreach ($ratings as $r) 
            {
                $comment = trim((string)$r->comment);
                if ($comment === '') { continue; }
                $exists = $DB->record_exists('spe_sentiment', 
                [
                    'speid'   => $cm->instance,
                    'raterid' => $u->id,
                    'rateeid' => $r->rateeid,
                    'type'    => 'peer_comment',
                    'text'    => $comment
                ]);
                if (!$exists) 
                {
                    $DB->insert_record('spe_sentiment', (object)
                    [
                        'speid'       => $cm->instance,
                        'raterid'     => $u->id,
                        'rateeid'     => $r->rateeid,
                        'type'        => 'peer_comment',
                        'text'        => $comment,
                        'status'      => 'pending',
                        'timecreated' => time()
                    ]);
                }
            }
        }
    }

    \core\notification::success('Queued texts for analysis.');

    // Hide rows from now onwards until a newer submission arrives.
    set_config($hidekey, time(), 'mod_spe');

    // Jump to analyzer
    redirect(new moodle_url('/mod/spe/analyze_push.php', 
    [
        'id' => $cm->id,
        'sesskey' => sesskey(),
    ]));
}

// Load disparity flags
$disparitycount = []; 
$disparitymap   = [];
$mgr = $DB->get_manager();
if ($mgr->table_exists('spe_disparity')) 
{
    $drows = $DB->get_records('spe_disparity', ['speid' => $cm->instance], '', 'raterid, rateeid');
    foreach ($drows as $d) 
    {
        $key = $d->raterid . '->' . $d->rateeid;
        $disparitymap[$key] = true;
        if (!isset($disparitycount[$d->raterid])) $disparitycount[$d->raterid] = 0;
        $disparitycount[$d->raterid]++;
    }
}

// Visible students 
$students = [];
if ($allstudents) 
{
    foreach ($allstudents as $u) 
    {
        $ts = (int)($u->timemodified ?: $u->timecreated);
        if ($ts > $hideafterts) 
        {
            $students[$u->id] = $u;
        }
    }
}

echo html_writer::tag('h2', 'Approvals & Queue for Analysis');

// "Run Analysis" button
$runallurl = new moodle_url('/mod/spe/instructor.php', 
[
    'id'      => $cm->id,
    'runall'  => 1,
    'sesskey' => sesskey()
]);
echo html_writer::div(
    html_writer::link($runallurl, 'Run Analysis', ['class' => 'btn btn-secondary']),
    'spe-runall-wrap',
    ['style' => 'text-align:right;margin:6px 0 2px;']
);

// Table of students
echo html_writer::start_tag('table', ['class' => 'generaltable']);

$queuedbyrater = [];
if ($students) 
{
    foreach ($students as $u) 
    {
        $queuedbyrater[$u->id] = (int)$DB->count_records('spe_sentiment', 
        [
            'speid'   => $cm->instance,
            'raterid' => $u->id
        ]);
    }
}

// Table header
$headcells = 
[
    html_writer::tag('th', 'Student'),
    html_writer::tag('th', 'Submission Time'),
    html_writer::tag('th', 'Queued Items'),
    html_writer::tag('th', 'Disparities'),
];
echo html_writer::tag('tr', implode('', $headcells));

// Rows for each student
if ($students) 
{
    foreach ($students as $u) 
    {
        $queuedtotal = $queuedbyrater[$u->id] ?? 0;

        $dcount = (int)($disparitycount[$u->id] ?? 0);
        if ($dcount > 0) 
        {
            $dcell = html_writer::span('Yes', '', 
            [
                'style' => 'background:#fff8b3;padding:2px 8px;border-radius:10px;display:inline-block;font-weight:600;'
            ]);
        } 
        else 
        {
            $dcell = '';
        }

        $cells = [];
        $cells[] = html_writer::tag('td', $mkname($u->firstname, $u->lastname) . " (" . s($u->username) . ")");
        $cells[] = html_writer::tag('td', userdate($u->timemodified ?: $u->timecreated));
        $cells[] = html_writer::tag('td', (string)$queuedtotal);
        $cells[] = html_writer::tag('td', $dcell);

        echo html_writer::tag('tr', implode('', $cells));
    }
}

echo html_writer::end_tag('table');

// GradeBook 
echo html_writer::tag('h2', 'SPE — GradeBook');
echo html_writer::div(
    $OUTPUT->single_button(
        new moodle_url('/mod/spe/gradebook.php', ['id' => $cm->id]),
        'Open Grade book',
        'get'
    ),
    'spe-gradebook-entry',
    ['style' => 'margin:10px 0 20px;']
);

echo $OUTPUT->footer();
