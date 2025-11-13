<?php

require('../../config.php');

$cmid    = required_param('id', PARAM_INT);         
$userid  = required_param('userid', PARAM_INT);     
$confirm = optional_param('confirm', 0, PARAM_INT); 

$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/spe:manage', $context);

$PAGE->set_url('/mod/spe/approve.php', ['id' => $cm->id, 'userid' => $userid]);
$PAGE->set_title('Approve SPE submission');
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading('Approve SPE submission');

$spe = $DB->get_record('spe', ['id' => $cm->instance], '*', MUST_EXIST);
$user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);

// Fetch the student submission
$submission = $DB->get_record('spe_submission', ['speid' => $spe->id, 'userid' => $userid], '*', IGNORE_MISSING);
if (!$submission) 
{
    echo $OUTPUT->notification('No submission found for this user.', 'notifyproblem');
    echo $OUTPUT->continue_button(new moodle_url('/mod/spe/view.php', ['id' => $cm->id]));
    echo $OUTPUT->footer();
    exit;
}

// Collect all ratings 
$ratings = $DB->get_records('spe_rating', ['speid' => $spe->id, 'raterid' => $userid], 'rateeid, criterion ASC');

if (!$confirm) 
{
    // Show confirmation screen 
    $summary = html_writer::start_div();
    $summary .= html_writer::tag('p', 'You are about to approve the submission for: <strong>' . fullname($user) . '</strong>.');
    $summary .= html_writer::tag('p', 'This will (re)queue the self-reflection and each peer comment for NLP analysis.');
    $summary .= html_writer::tag('p', 'Existing ratings/comments have already been saved by the student form; approval only controls the NLP queueing step.');
    $summary .= html_writer::end_div();

    echo $summary;

    $url = new moodle_url('/mod/spe/approve.php', ['id' => $cm->id, 'userid' => $userid, 'confirm' => 1, 'sesskey' => sesskey()]);
    echo $OUTPUT->single_button($url, 'Approve & Queue for NLP', 'post');
    echo $OUTPUT->continue_button(new moodle_url('/mod/spe/view.php', ['id' => $cm->id]));
    echo $OUTPUT->footer();
    exit;
}

require_sesskey();

// Queue texts into spe_sentiment
if ($DB->get_manager()->table_exists('spe_sentiment') && !empty($submission->reflection)) 
{
    $DB->insert_record('spe_sentiment', (object)[
        'speid'       => $spe->id,
        'raterid'     => $userid,
        'rateeid'     => $userid,
        'type'        => 'reflection',
        'text'        => $submission->reflection,
        'status'      => 'pending',
        'timecreated' => time()
    ]);
}

// Peer comments
$peercomments = [];
foreach ($ratings as $r) 
{
    if (!empty($r->comment)) 
    {
        $peercomments[$r->rateeid] = $r->comment;
    }
}
foreach ($peercomments as $rateeid => $commenttext) 
{
    if ($DB->get_manager()->table_exists('spe_sentiment') && trim($commenttext) !== '') 
    {
        $DB->insert_record('spe_sentiment', (object)
        [
            'speid'       => $spe->id,
            'raterid'     => $userid,
            'rateeid'     => $rateeid,
            'type'        => 'peer_comment',
            'text'        => $commenttext,
            'status'      => 'pending',
            'timecreated' => time()
        ]);
    }
}

echo $OUTPUT->notification('Submission approved and items queued for NLP.', 'notifysuccess');

$links = html_writer::alist([
    html_writer::link(
        new moodle_url('/mod/spe/analyze_push.php', ['id' => $cm->id, 'sesskey' => sesskey()]),
        'Send queued texts to NLP now'
    ),
    html_writer::link(
        new moodle_url('/mod/spe/analysis_report.php', ['id' => $cm->id]),
        'View analysis report'
    )
], [], 'ul');
echo $links;


echo $OUTPUT->continue_button(new moodle_url('/mod/spe/view.php', ['id' => $cm->id]));
echo $OUTPUT->footer();
