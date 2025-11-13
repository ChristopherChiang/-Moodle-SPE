<?php

require('../../config.php');

$cmid   = required_param('id', PARAM_INT);
$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);

// Enforce staff-only access to results
require_capability('mod/spe:viewresults', $context);

// Set page
$PAGE->set_url('/mod/spe/results.php', ['id' => $cm->id]);
$PAGE->set_title('SPE — Sentiment Results');
$PAGE->set_heading(format_string($course->fullname));

$PAGE->set_pagelayout('incourse');
$PAGE->add_body_class('spe-compact spe-left');


echo $OUTPUT->header();
echo $OUTPUT->heading('SPE — Sentiment Results', 2);

$canmanage = has_capability('mod/spe:manage', $context);

// Fetch analysis rows 
$params = ['speid' => $cm->instance, 'status' => 'done'];
$where  = "speid = :speid AND status = :status";
$rows   = $DB->get_records_select('spe_sentiment', $where, $params, 'raterid, rateeid, id');

if (!$rows) 
{
    echo $OUTPUT->notification(
        'No analyzed results yet. Use the Instructor page to queue texts and push for analysis.',
        \core\output\notification::NOTIFY_INFO
    );

    if ($canmanage) 
    {
        $instrurl = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
        echo html_writer::tag('p', html_writer::link($instrurl, 'Go to Instructor'));
    }

    // Export page links
    $here    = $PAGE->url->out_as_local_url(false);
    $csvpage = new moodle_url('/mod/spe/export_csv.php', ['id' => $cm->id, 'returnurl' => $here]);
    $pdfpage = new moodle_url('/mod/spe/export_pdf.php', ['id' => $cm->id, 'returnurl' => $here]);
    echo html_writer::div(
        html_writer::link($csvpage, 'CSV export page') . ' | ' .
        html_writer::link($pdfpage, 'PDF export page'),
        'spe-export-links',
        ['style' => 'margin-top:12px;']
    );

    echo $OUTPUT->footer();
    exit;
}

// Fetch user names 
$userids = [];
foreach ($rows as $r) 
{
    $userids[$r->raterid] = true;
    $userids[$r->rateeid] = true;
}

$users = [];
if ($userids) 
{
    list($insql, $inparams) = $DB->get_in_or_equal(array_keys($userids), SQL_PARAMS_NAMED, 'u');
    $users = $DB->get_records_select('user', "id $insql", $inparams, '', 'id, firstname, lastname, username');
}

// Render results table.
echo html_writer::start_tag('table', ['class' => 'generaltable spe-results']);
echo html_writer::tag('tr',
    html_writer::tag('th', 'Rater') .
    html_writer::tag('th', 'Target') .
    html_writer::tag('th', 'Type') .
    html_writer::tag('th', 'Label') .
    html_writer::tag('th', 'Score (compound)') .
    html_writer::tag('th', 'Excerpt'));

foreach ($rows as $r) 
{
    $rater = $users[$r->raterid] ?? null;
    $ratee = $users[$r->rateeid] ?? null;

    $rname = $rater ? fullname($rater) . " ({$rater->username})" : (string)$r->raterid;
    $tname = $ratee ? fullname($ratee) . " ({$ratee->username})" : (string)$r->rateeid;

    $label    = s((string)$r->label);
    $compound = is_null($r->sentiment) ? '' : sprintf('%.3f', (float)$r->sentiment);

    $rawtext  = (string)$r->text;
    $excerpt  = s(core_text::substr(clean_text($rawtext), 0, 120)) .
                (core_text::strlen($rawtext) > 120 ? '…' : '');

    $style = '';
    if ($r->label === 'positive')       
    { 
        $style = 'color:#1a7f37;'; 
    }
    else if ($r->label === 'negative')  
    { 
        $style = 'color:#b42318;'; 
    }
    else if ($r->label === 'neutral')   
    { 
        $style = 'color:#555;'; 
    }

    echo html_writer::tag('tr',
        html_writer::tag('td', $rname) .
        html_writer::tag('td', $tname) .
        html_writer::tag('td', s((string)$r->type)) .
        html_writer::tag('td', html_writer::tag('strong', $label, ['style' => $style])) .
        html_writer::tag('td', $compound) .
        html_writer::tag('td', $excerpt)
    );
}
echo html_writer::end_tag('table');

// Export page links
$here    = $PAGE->url->out_as_local_url(false);
$csvpage = new moodle_url('/mod/spe/export_csv.php', ['id' => $cm->id, 'returnurl' => $here]);
$pdfpage = new moodle_url('/mod/spe/export_pdf.php', ['id' => $cm->id, 'returnurl' => $here]);
echo html_writer::div(
    html_writer::link($csvpage, 'CSV export page') . ' | ' .
    html_writer::link($pdfpage, 'PDF export page'),
    'spe-export-links',
    ['style' => 'margin-top:12px;']);

echo $OUTPUT->footer();
