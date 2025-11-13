<?php

require('../../config.php');

$cmid    = required_param('id', PARAM_INT);
$userid  = required_param('userid', PARAM_INT);

$cm      = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$ratee   = $DB->get_record('user', ['id' => $userid], 'id, firstname, lastname, username', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/spe:manage', $context);

// Page
$PAGE->set_url('/mod/spe/grade_detail.php', ['id' => $cm->id, 'userid' => $userid]);
$PAGE->set_title('SPE — Student ratings detail');
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();

// Export links
if (has_capability('mod/spe:viewreports', $context)) 
{
    $here    = $PAGE->url->out_as_local_url(false);
    $csvpage = new moodle_url('/mod/spe/export_csv.php', ['id' => $cm->id, 'returnurl' => $here]);
    $pdfpage = new moodle_url('/mod/spe/export_pdf.php', ['id' => $cm->id, 'returnurl' => $here]);

    echo html_writer::div(
        html_writer::link($csvpage, 'CSV export page') . ' | ' .
        html_writer::link($pdfpage, 'PDF export page'),
        'spe-export-links',
        ['style' => 'margin:10px 0;']
    );
}

echo $OUTPUT->heading('Ratings received by: ' . fullname($ratee) . ' (' . s($ratee->username) . ')');

// Criteria labels 
$criteria = 
[
    'effortdocs'    => 'Effort',
    'teamwork'      => 'Teamwork',
    'communication' => 'Communication',
    'management'    => 'Management',
    'problemsolve'  => 'Problem solving',
];

// Pull all peer ratings for this ratee in this activity 
$sql = "SELECT
            r.id,
            r.raterid,
            r.criterion,
            r.score,
            r.comment,
            r.timecreated
        FROM {spe_rating} r
        WHERE r.speid   = :speid
          AND r.rateeid = :rateeid
          AND r.raterid <> r.rateeid
        ORDER BY r.raterid, r.timecreated ASC";
$params = ['speid' => $cm->instance, 'rateeid' => $ratee->id];

$rows = $DB->get_records_sql($sql, $params);

if (!$rows) 
{
    echo $OUTPUT->notification('No peer ratings for this student yet.', 'notifyinfo');
    $backurl = new moodle_url('/mod/spe/gradebook.php', ['id' => $cm->id]);
    echo html_writer::div($OUTPUT->single_button($backurl, '← Back to Grade book', 'get'), 'mt-3');
    echo $OUTPUT->footer();
    exit;
}

// Group by rater
$byrater = [];
$raterids = [];
foreach ($rows as $r) 
{
    $rid = (int)$r->raterid;
    $byrater[$rid][] = $r;
    $raterids[$rid] = true;
}

// Fetch rater user records
$rusers = [];
if ($raterids) 
{
    list($rinsql, $rinparams) = $DB->get_in_or_equal(array_keys($raterids), SQL_PARAMS_NAMED, 'ru');
    $rusers = $DB->get_records_select('user', "id $rinsql", $rinparams, '', 'id, firstname, lastname, username');
}

// Badge renderer
$badge = function(string $text, string $bg = '#eee', string $fg = '#000'): string {
    return (string) html_writer::tag('span', $text, [
        'style' => "display:inline-block;background:{$bg};color:{$fg};padding:2px 8px;border-radius:10px;font-weight:600;"
    ]);
};

// Function to get sentiment label for a (rater -> ratee) pair and comment
$mgr = $DB->get_manager();
$get_sentiment_label = function(int $raterid, int $rateeid, string $comment) use ($DB, $cm, $mgr): string {
    if (!$mgr->table_exists('spe_sentiment')) {
        return '';
    }

    // Exact match first
    $rec = $DB->get_record_select(
        'spe_sentiment',
        "speid = :speid AND raterid = :raterid AND rateeid = :rateeid AND type = :type AND text = :text",
        [
            'speid'   => $cm->instance,
            'raterid' => $raterid,
            'rateeid' => $rateeid,
            'type'    => 'peer_comment',
            'text'    => $comment,
        ],
        '*',
        IGNORE_MULTIPLE
    );

    // If not found, get the latest one for this pair
    if (!$rec) 
    {
        $recs = $DB->get_records(
            'spe_sentiment',
            ['speid' => $cm->instance, 'raterid' => $raterid, 'rateeid' => $rateeid, 'type' => 'peer_comment'],
            'timemodified DESC, timecreated DESC',
            '*',
            0, 1
        );
        if ($recs) 
        { 
            $rec = reset($recs); 
        }
    }

    if (!$rec) 
    { 
        return ''; 
    }

    foreach (['label','sentiment','category','result','polarity','status'] as $field) 
    {
        if (isset($rec->$field) && trim((string)$rec->$field) !== '') 
        {
            return (string)$rec->$field;
        }
    }
    return '';
};

// Render per-rater block
foreach ($byrater as $rid => $items) 
{
    $r = $rusers[$rid] ?? null;
    $rname = $r ? fullname($r) . ' (' . s($r->username) . ')' : 'User ID ' . $rid;

    echo html_writer::tag('h3', 'Rater: ' . $rname);

    $critvals = [];
    $comment  = '';
    $total    = 0;

    foreach ($criteria as $ckey => $clabel) 
    {
        $critvals[$ckey] = null;
    }

    foreach ($items as $it) 
    {
        $ckey = (string)$it->criterion;
        if (array_key_exists($ckey, $critvals)) 
        {
            $critvals[$ckey] = (int)$it->score;
            $total += (int)$it->score;
        }
        if ($comment === '' && !empty($it->comment)) 
        {
            $comment = (string)$it->comment;
        }
    }

    // Table with criteria scores
    $table = new html_table();
    $table->head = ['Criterion', 'Score'];

    foreach ($criteria as $ckey => $clabel) 
    {
        $val = is_null($critvals[$ckey]) ? '-' : (string)$critvals[$ckey];
        $table->data[] = [$clabel, $val];
    }
    $table->data[] = [ html_writer::tag('strong','Total'), html_writer::tag('strong', (string)$total) ];

    echo html_writer::table($table);

    // Comment 
    echo html_writer::tag('p', html_writer::tag('strong', 'Comment:'));
    echo html_writer::tag('blockquote', s($comment));

    // Sentiment label badge
    $sentlabel = $get_sentiment_label($rid, $ratee->id, (string)$comment);
    if ($sentlabel !== '') 
    {
        $sentlc = core_text::strtolower($sentlabel);
        if (strpos($sentlc, 'neg') !== false || strpos($sentlc, 'toxic') !== false || strpos($sentlc, 'bad') !== false) 
        {
            $sentbadge = $badge('Sentiment: ' . s($sentlabel), '#ffd6d6', '#900');
        } 
        else if (strpos($sentlc, 'pos') !== false || strpos($sentlc, 'good') !== false) 
        {
            $sentbadge = $badge('Sentiment: ' . s($sentlabel), '#d6f5e3', '#075');
        } 
        else if ($sentlc === 'pending') 
        {
            $sentbadge = $badge('Sentiment: Pending', '#fff6cc', 'rgba(85, 170, 102, 1)');
        } 
        else 
        {
            $sentbadge = $badge('Sentiment: ' . s($sentlabel), '#e9e9e9', '#333');
        }
    } 
    else 
    {
        $sentbadge = $badge('Sentiment: —', '#e9e9e9', '#333');
    }
    
    $badgeline = '';

    // Fetch disparity row 
    $disp = $DB->get_record('spe_disparity', 
    [
        'speid'   => $cm->instance,
        'raterid' => (int)$rid,
        'rateeid' => (int)$ratee->id
    ], 'isdisparity, commenttext', IGNORE_MISSING);

    $hasdisp = ($disp && (int)$disp->isdisparity === 1);

    if ($hasdisp) 
    {
        $reason = trim((string)($disp->commenttext ?? ''));

        $content = html_writer::tag('strong', 'Disparity: Yes');

        if ($reason !== '') 
        {
            $content .= html_writer::tag('span', '. ' . s($reason), [
                'style' => 'font-weight:normal; color:#333; margin-left:4px;'
            ]);
        }

        $badgeline .= html_writer::div($content, '', 
        [
            'style' => 'display:inline-block; background:#fff69b; color:#333; border-radius:12px; padding:4px 10px; font-size:15px; margin-right:6px;'
        ]);
    };

    $labelsline = $sentbadge . ' ' . $badgeline;
    
    echo html_writer::div($labelsline, '', ['style' => 'margin:8px 0 15px;']);

    echo html_writer::empty_tag('hr');
}

// Back button
$backurl = new moodle_url('/mod/spe/gradebook.php', ['id' => $cm->id]);
echo html_writer::div($OUTPUT->single_button($backurl, '← Back to Grade book', 'get'), 'mt-3');

echo $OUTPUT->footer();
