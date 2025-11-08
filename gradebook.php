<?php
// mod/spe/gradebook.php
//
// Instructor Grade Book view — no group mode.
// Lists per-criterion sums + live-calculated columns:
//   - Evaluation (50%)   = ( received_total / (raters * 25) ) * 50
//   - Sentiment (50%)    = ( avg normalized sentiment 0..1 ) * 50
//   - Total (100%)       = Evaluation (50%) + Sentiment (50%)
// Adds a search bar to filter by student name/username.

require('../../config.php');

$cmid   = required_param('id', PARAM_INT);
$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/spe:manage', $context);

// Optional search query
$q = optional_param('q', '', PARAM_RAW_TRIMMED);
$qnorm = core_text::strtolower((string)$q);

// Page setup
$PAGE->set_url('/mod/spe/gradebook.php', ['id' => $cm->id, 'q' => $q]);
$PAGE->set_title('SPE — Grade book');
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();
echo $OUTPUT->heading('SPE — Grade book');

// === Export links (CSV | PDF) just below the heading ===
if (has_capability('mod/spe:viewreports', $context)) {
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

// Criterion headers (keys MUST match DB values exactly)
$criteria = [
    'effortdocs'    => 'Effort on docs',
    'teamwork'      => 'Teamwork',
    'communication' => 'Communication',
    'management'    => 'Management',
    'problemsolve'  => 'Problem solving',
];

// --- Build user list (participants who received or submitted)
$userids = [];

// Everyone who received any rating
$list = $DB->get_fieldset_sql(
    "SELECT DISTINCT rateeid FROM {spe_rating} WHERE speid = :s",
    ['s' => $cm->instance]
);
foreach ($list as $uid) { $userids[(int)$uid] = true; }

// Plus anyone who submitted (safety net)
$list2 = $DB->get_fieldset_sql(
    "SELECT DISTINCT userid FROM {spe_submission} WHERE speid = :s",
    ['s' => $cm->instance]
);
foreach ($list2 as $uid) { $userids[(int)$uid] = true; }

if (!$userids) {
    echo $OUTPUT->notification('No participants detected for this activity.', 'notifyinfo');
    echo $OUTPUT->footer();
    exit;
}

// Load user info
list($uinsql, $uinparams) = $DB->get_in_or_equal(array_keys($userids), SQL_PARAMS_NAMED, 'u');
$users = $DB->get_records_select('user', "id $uinsql", $uinparams, '', 'id, firstname, lastname, username');

// Aggregate peer-received ratings (exclude self-ratings)
$params = ['speid' => $cm->instance];

$matrix = [];
$rs = $DB->get_recordset_sql("
    SELECT r.rateeid,
           LOWER(TRIM(r.criterion)) AS ckey,
           SUM(r.score) AS sumscore
      FROM {spe_rating} r
     WHERE r.speid = :speid
       AND r.raterid <> r.rateeid
  GROUP BY r.rateeid, LOWER(TRIM(r.criterion))
", $params);

foreach ($rs as $r) {
    $rid  = (int)$r->rateeid;
    $ckey = (string)$r->ckey;
    if ($ckey === 'problemsolving' || $ckey === 'problem solving') {
        $ckey = 'problemsolve';
    }
    if (!array_key_exists($ckey, $criteria)) { continue; }
    if (!isset($matrix[$rid])) { $matrix[$rid] = []; }
    $matrix[$rid][$ckey] = (int)$r->sumscore;
}
$rs->close();

// Count unique raters per ratee (exclude self-ratings)
$sqlraters = "SELECT rateeid, COUNT(DISTINCT raterid) AS raters
                FROM {spe_rating}
               WHERE speid = :speid
                 AND raterid <> rateeid
            GROUP BY rateeid";
$raters = $DB->get_records_sql($sqlraters, $params);

// --- Disparity detection (YES if any rater flagged disparity for that rateeid)
$disparity = []; // rateeid => true
$mgr = $DB->get_manager();
if ($mgr->table_exists('spe_disparity')) {
    $disp_rows = $DB->get_records_sql("
        SELECT DISTINCT rateeid
          FROM {spe_disparity}
         WHERE speid = :speid
           AND isdisparity = 1
    ", ['speid' => $cm->instance]);
    foreach ($disp_rows as $rowobj) {
        $disparity[(int)$rowobj->rateeid] = true;
    }
}

// --- Search bar
$searchurl = new moodle_url('/mod/spe/gradebook.php', ['id' => $cm->id]);
echo html_writer::start_div('', ['style' => 'display:flex; justify-content:flex-end; margin-bottom:8px;']);
echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => $searchurl->out(false),
    'style'  => 'display:flex; gap:6px; align-items:center;'
]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => (string)$cm->id]);
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'q', 'value' => s($q),
    'placeholder' => 'Search student', 'style' => 'max-width:260px;'
]);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Search', 'class' => 'btn btn-secondary']);
if ($q !== '') {
    $clearurl = new moodle_url('/mod/spe/gradebook.php', ['id' => $cm->id]);
    echo html_writer::link($clearurl, 'Clear');
}
echo html_writer::end_tag('form');
echo html_writer::end_div();

// Build table
$table = new html_table();
$table->attributes['class'] = 'generaltable';

$headlabels = ['Student'];
foreach ($criteria as $key => $label) { $headlabels[] = $label; }
$headlabels[] = 'Evaluation (50%)';
$headlabels[] = 'Sentiment (50%)';
$headlabels[] = 'Total (100%)';
$headlabels[] = 'Disparity';
$table->head = array_map('strval', $headlabels);

// Rows
$table->data = [];
$shown = 0;

foreach ($users as $uid => $u) {
    // Apply search filter
    if ($qnorm !== '') {
        $namestr = core_text::strtolower(fullname($u) . ' (' . $u->username . ')');
        if (core_text::strpos($namestr, $qnorm) === false) { continue; }
    }

    $name = fullname($u) . ' (' . s($u->username) . ')';
    $link = new moodle_url('/mod/spe/grade_detail.php', ['id' => $cm->id, 'userid' => $uid]);

    $row = [];
    $row[] = (string) html_writer::link($link, $name);

    // Per-criterion sums (displayed)
    $sumtotal = 0;
    foreach ($criteria as $ckey => $_label) {
        $v = isset($matrix[$uid][$ckey]) ? (int)$matrix[$uid][$ckey] : 0;
        $sumtotal += $v;           // this is total points received across all raters
        $row[] = (string)$v;
    }

    // Number of unique raters
    $ratercount = isset($raters[$uid]) ? (int)$raters[$uid]->raters : 0;

    // --- Evaluation (50%)
    // Max per rater is 25 points across 5 criteria.
    // evaluation_50 = (sumtotal / (ratercount * 25)) * 50
    $eval50 = '-';
    if ($ratercount > 0) {
        $den = $ratercount * 25.0;
        $ratio = $den > 0 ? ($sumtotal / $den) : 0.0;        // 0..1
        $ratio = max(0.0, min(1.0, $ratio));
        $eval50 = round($ratio * 50.0, 1);                   // 0..50
    }

    // --- Sentiment (50%) from spe_sentiment.sentiment (0..1), peer_comment only
    $avgnorm = $DB->get_field_sql("
        SELECT AVG(s.sentiment)
          FROM {spe_sentiment} s
         WHERE s.speid   = :speid
           AND s.rateeid = :uid
           AND s.type    = 'peer_comment'
    ", ['speid' => $cm->instance, 'uid' => $uid]);

    if ($avgnorm === false || $avgnorm === null) { $avgnorm = 0.5; } // neutral if none
    $avgnorm = (float)$avgnorm;
    if ($avgnorm < 0) $avgnorm = 0.0;
    if ($avgnorm > 1) $avgnorm = 1.0;

    $sent50 = round($avgnorm * 50.0, 1);                      // 0..50

    // --- Total (100%)
    $total100 = is_numeric($eval50) ? round($eval50 + $sent50, 1) : '-';

    // Append new columns
    $row[] = is_numeric($eval50)   ? ($eval50   . ' %') : $eval50;
    $row[] = ($sent50 . ' %');
    $row[] = is_numeric($total100) ? ($total100 . ' %') : $total100;

    // Disparity
    if (!empty($disparity[$uid])) {
        $row[] = (string) html_writer::tag('span', 'Yes', [
            'style' => 'background:#ff0; padding:0 6px; border-radius:3px; font-weight:600;'
        ]);
    } else {
        $row[] = '';
    }

    // Force all cells to strings
    foreach ($row as $i => $cellval) {
        if ($cellval instanceof html_table_cell) { $cellval = $cellval->text; }
        if ($cellval instanceof html_table_row)  { $cellval = ''; }
        if (is_array($cellval))                  { $cellval = json_encode($cellval); }
        $row[$i] = (string)$cellval;
    }
    $table->data[] = array_values($row);
    $shown++;
}

// Final hardening (headers/cells as strings)
if (!empty($table->head)) {
    foreach ($table->head as $i => $h) {
        if ($h instanceof html_table_cell) { $h = $h->text; }
        if (is_array($h)) { $h = json_encode($h); }
        $table->head[$i] = (string)$h;
    }
}
if (!empty($table->data)) {
    foreach ($table->data as $r => $row) {
        foreach ($row as $c => $cell) {
            if ($cell instanceof html_table_cell) { $cell = $cell->text; }
            if ($cell instanceof html_table_row)  { $cell = ''; }
            if (is_array($cell))                  { $cell = json_encode($cell); }
            $table->data[$r][$c] = (string)$cell;
        }
        $table->data[$r] = array_values($table->data[$r]);
    }
}

// Intro message
echo (string) html_writer::div(
    (string) html_writer::tag('p',
        'Click a student’s name to see all individual raters with per-criterion scores and peer comments.'
        . ($q !== '' ? ' Showing results for: ' . s($q) : '')
    )
);

// Table
echo (string) html_writer::table($table);

// Back button
$backurl = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
echo $OUTPUT->single_button($backurl, '← Back to Instructor', 'get');

echo $OUTPUT->footer();
