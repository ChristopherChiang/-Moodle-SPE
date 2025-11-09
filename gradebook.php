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

// -------- Sorting (arrow only after user clicks) --------
// $sortparam = raw value from URL, used ONLY to decide whether to show an arrow.
// $sort      = effective sort key used for data; defaults to 'name' when no user choice yet.
$sortparam = optional_param('sort', '',     PARAM_ALPHANUMEXT); // '' means: first load, no user sort chosen
$dir       = optional_param('dir',  'asc',  PARAM_ALPHA);
$dir       = ($dir === 'desc') ? 'desc' : 'asc';

$sort = $sortparam ?: 'name';  // still sort data by name ASC initially

// Page setup (omit sort/dir when none chosen so no arrow shows on first load)
$urlparams = ['id' => $cm->id, 'q' => $q];
if ($sortparam !== '') { $urlparams['sort'] = $sortparam; $urlparams['dir'] = $dir; }
$PAGE->set_url('/mod/spe/gradebook.php', $urlparams);
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
// keep raw sort from URL (so we preserve user-chosen column/dir after searching)
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sort', 'value' => s($sortparam)]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'dir',  'value' => s($dir)]);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Search', 'class' => 'btn btn-secondary']);
if ($q !== '') {
    // If you want Clear to also reset sorting & hide arrow, omit sort/dir from this URL:
    $clearurl = new moodle_url('/mod/spe/gradebook.php', ['id' => $cm->id]);
    echo html_writer::link($clearurl, 'Clear');
}
echo html_writer::end_tag('form');
echo html_writer::end_div();

// Build table
$table = new html_table();
$table->attributes['class'] = 'generaltable';

// Helper to build sortable header links.
// Important: use $sortparam (not $sort) to decide whether to show the arrow.
$baseurl = new moodle_url('/mod/spe/gradebook.php', ['id' => $cm->id, 'q' => $q]);
$make_header = function(string $label, string $key) use ($baseurl, $sortparam, $dir) : string {
    // If user already sorted by this key → toggle direction; else start at ASC
    $newdir = ($sortparam === $key && $dir === 'asc') ? 'desc' : 'asc';
    $url = new moodle_url($baseurl, ['sort' => $key, 'dir' => $newdir]);

    // Only show arrow when the user explicitly chose this column
    $arrow = '';
    if ($sortparam === $key && $sortparam !== '') {
        $arrow = ($dir === 'asc') ? ' ▲' : ' ▼';
    }
    return html_writer::link($url, $label . $arrow);
};

$headlabels = [];
$headlabels[] = $make_header('Student', 'name');     // Student shows no arrow until user clicks
$headlabels[] = $make_header('Groups',  'groups');   // New column
foreach ($criteria as $key => $label) {
    $headlabels[] = $make_header($label, $key);
}
$headlabels[] = $make_header('Evaluation (50%)', 'eval');
$headlabels[] = $make_header('Sentiment (50%)',  'sent');
$headlabels[] = $make_header('Total (100%)',     'total');
$headlabels[] = $make_header('Disparity',        'disparity');
$table->head = array_map('strval', $headlabels);

// Rows (collect first to allow post-build sorting)
$table->data = [];
$rows = [];

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

    // Groups for this user (course-level)
    $groups = groups_get_all_groups($course->id, $uid, 0, 'g.id, g.name');
    $groupnames = [];
    if ($groups) {
        foreach ($groups as $g) {
            $groupnames[] = format_string($g->name);
        }
    }
    $groupsstr = $groupnames ? implode(', ', $groupnames) : '-';
    $row[] = (string)$groupsstr;

    // Per-criterion sums (displayed)
    $sumtotal = 0;
    $percrit  = [];
    foreach ($criteria as $ckey => $_label) {
        $v = isset($matrix[$uid][$ckey]) ? (int)$matrix[$uid][$ckey] : 0;
        $sumtotal += $v;
        $percrit[$ckey] = $v;
        $row[] = (string)$v;
    }

    // Number of unique raters
    $ratercount = isset($raters[$uid]) ? (int)$raters[$uid]->raters : 0;

    // --- Evaluation (50%)
    $eval50 = '-';
    if ($ratercount > 0) {
        $den = $ratercount * 25.0;
        $ratio = $den > 0 ? ($sumtotal / $den) : 0.0;        // 0..1
        $ratio = max(0.0, min(1.0, $ratio));
        $eval50 = round($ratio * 50.0, 1);                   // 0..50
    }

    // --- Sentiment (50%) — peer_comment only
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
    $hasdisp = !empty($disparity[$uid]);
    if ($hasdisp) {
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

    // Collect row & values for sorting
    $rows[] = [
        'uid'      => (int)$uid,
        'row'      => array_values($row),
        'sortvals' => [
            'name'       => core_text::strtolower(fullname($u) . ' (' . $u->username . ')'),
            'groups'     => core_text::strtolower($groupsstr),
            'eval'       => is_numeric($eval50) ? (float)$eval50 : -INF,
            'sent'       => (float)$sent50,
            'total'      => is_numeric($total100) ? (float)$total100 : -INF,
            'disparity'  => $hasdisp ? 1 : 0
        ] + $percrit
    ];
}

// -------- Post-build sort (uses effective $sort) --------
$validkeys = array_merge(
    ['name'=>true,'groups'=>true,'eval'=>true,'sent'=>true,'total'=>true,'disparity'=>true],
    array_fill_keys(array_keys($criteria), true)
);
if (!array_key_exists($sort, $validkeys)) {
    $sort = 'name';
}
usort($rows, function($a, $b) use ($sort, $dir) {
    $av = $a['sortvals'][$sort] ?? null;
    $bv = $b['sortvals'][$sort] ?? null;

    $anum = is_numeric($av);
    $bnum = is_numeric($bv);
    if ($anum && $bnum) {
        $cmp = ($av <=> $bv);
    } else {
        $as = (string)$av;
        $bs = (string)$bv;
        $cmp = strcoll($as, $bs);
        if ($cmp === 0) { $cmp = ($a['uid'] <=> $b['uid']); }
    }
    return ($dir === 'asc') ? $cmp : -$cmp;
});

// Push sorted rows to table
foreach ($rows as $r) {
    $table->data[] = $r['row'];
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
