<?php

require('../../config.php');

$cmid     = required_param('id', PARAM_INT);
$download = optional_param('download', 0, PARAM_BOOL);
$userid   = optional_param('userid', 0, PARAM_INT); // if set => grade_detail export

$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);

// Allow admins and instructors
$allowedcaps = ['mod/spe:viewresults', 'mod/spe:viewreports', 'mod/spe:manage'];
if (!has_any_capability($allowedcaps, $context)) {
    require_capability('mod/spe:viewresults', $context);
}

// Back link
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
if (!$returnurl) {
    $ref = get_local_referer(false);
    $returnurl = $ref ? $ref : (new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]))->out(false);
}

// If userid is not provided explicitly, try to infer from returnurl (when coming from grade_detail.php).
if (!$userid && $returnurl) {
    // attempt to parse userid from returnurl query string
    $parts = parse_url($returnurl);
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $qs);
        if (!empty($qs['userid']) && ctype_digit((string)$qs['userid'])) {
            $userid = (int)$qs['userid'];
        }
    }
}

// Normal page display mode: show a “Back” link and auto-start download (preserve userid if present).
if (!$download) {
    $PAGE->set_url('/mod/spe/export_csv.php', ['id' => $cm->id, 'returnurl' => $returnurl] + ($userid ? ['userid' => $userid] : []));
    $PAGE->set_context($context);
    $PAGE->set_title('SPE — Export CSV');
    $PAGE->set_heading(format_string($course->fullname));

    echo $OUTPUT->header();
    echo $OUTPUT->heading('Analysis Results & Exports', 2);

    echo html_writer::div(
        html_writer::link(new moodle_url($returnurl), '← Back'),
        '',
        ['style' => 'margin-bottom:12px;']
    );

    $dlurl = new moodle_url('/mod/spe/export_csv.php', ['id' => $cm->id, 'download' => 1] + ($userid ? ['userid' => $userid] : []));
    echo html_writer::div(html_writer::link($dlurl, 'Start CSV download'), '', ['style' => 'margin-top:12px;']);
    echo html_writer::tag('script', "window.location.href = " . json_encode($dlurl->out(false)) . ";");

    echo $OUTPUT->footer();
    exit;
}

// -------------------- Download mode below --------------------
define('NO_OUTPUT_BUFFERING', true);
require_once($CFG->libdir . '/grouplib.php');

if (class_exists('\core\session\manager')) { \core\session\manager::write_close(); }
while (ob_get_level()) { ob_end_clean(); }
ignore_user_abort(true);
raise_memory_limit(MEMORY_EXTRA);
@set_time_limit(0);

$speid = (int)$cm->instance;

$fail = function(string $msg) {
    header_remove();
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $msg;
    exit;
};

// ---------- Helpers shared by both modes ----------

// Disparity check for a rateeid (gradebook-mode)
function load_disparity_map_for_rateeids(int $speid, array $rateeids): array {
    global $DB;
    if (empty($rateeids)) return [];
    list($insql, $inparams) = $DB->get_in_or_equal($rateeids, SQL_PARAMS_NAMED, 'd');
    $rows = $DB->get_records_sql("
        SELECT DISTINCT rateeid
          FROM {spe_disparity}
         WHERE speid = :s AND isdisparity = 1 AND rateeid $insql
    ", ['s'=>$speid] + $inparams);
    $map = [];
    foreach ($rows as $r) { $map[(int)$r->rateeid] = true; }
    return $map;
}

// Sentiment label getter (grade_detail-mode; mirrors grade_detail.php)
function get_sentiment_label_for_pair(int $speid, int $raterid, int $rateeid, string $comment): string {
    global $DB;
    $mgr = $DB->get_manager();
    if (!$mgr->table_exists('spe_sentiment')) return '';

    // exact text match
    $rec = $DB->get_record_select(
        'spe_sentiment',
        "speid = :speid AND raterid = :raterid AND rateeid = :rateeid AND type = :type AND text = :text",
        [
            'speid'   => $speid,
            'raterid' => $raterid,
            'rateeid' => $rateeid,
            'type'    => 'peer_comment',
            'text'    => $comment,
        ],
        '*',
        IGNORE_MULTIPLE
    );

    // fallback latest
    if (!$rec) {
        $recs = $DB->get_records(
            'spe_sentiment',
            ['speid' => $speid, 'raterid' => $raterid, 'rateeid' => $rateeid, 'type' => 'peer_comment'],
            'timemodified DESC, timecreated DESC',
            '*',
            0, 1
        );
        if ($recs) { $rec = reset($recs); }
    }
    if (!$rec) return '';
    foreach (['label','sentiment','category','result','polarity','status'] as $field) {
        if (isset($rec->$field) && trim((string)$rec->$field) !== '') {
            return (string)$rec->$field;
        }
    }
    return '';
}

// ---------- MODE A: grade_detail export (if $userid > 0) ----------
if ($userid > 0) {
    // Load all peer ratings for this ratee (exclude self)
    $sql = "SELECT r.id, r.raterid, r.criterion, r.score, r.comment, r.timecreated
              FROM {spe_rating} r
             WHERE r.speid = :speid
               AND r.rateeid = :rateeid
               AND r.raterid <> r.rateeid
          ORDER BY r.raterid, r.timecreated ASC";
    $rows = $DB->get_records_sql($sql, ['speid' => $speid, 'rateeid' => $userid]);

    if (!$rows) { $fail('No peer ratings for this student yet.'); }

    // group by rater
    $byrater = [];
    $raterids = [];
    foreach ($rows as $r) {
        $rid = (int)$r->raterid;
        $byrater[$rid][] = $r;
        $raterids[$rid] = true;
    }

    // rater users
    $rusers = [];
    if ($raterids) {
        list($insql, $inparams) = $DB->get_in_or_equal(array_keys($raterids), SQL_PARAMS_NAMED, 'ru');
        $rusers = $DB->get_records_select('user', "id $insql", $inparams, '', 'id, firstname, lastname, username');
    }

    // CSV headers
    $ratee = core_user::get_user($userid, 'id,firstname,lastname,username', IGNORE_MISSING);
    $ratee_name = $ratee ? (fullname($ratee) . ' (' . $ratee->username . ')') : (string)$userid;

    $filename = 'spe_grade_detail.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: private, must-revalidate');
    header('Pragma: public');

    $out = fopen('php://output', 'w');
    if (!$out) { $fail('Cannot open output stream.'); }
    fputs($out, "\xEF\xBB\xBF"); // BOM for Excel

    // heading line like page title
    fputcsv($out, ['Ratings received by', $ratee_name]);
    fputcsv($out, ['']); // blank

    // criteria labels (match page)
    $criteria = [
        'effortdocs'    => 'Effort on docs',
        'teamwork'      => 'Teamwork',
        'communication' => 'Communication',
        'management'    => 'Management',
        'problemsolve'  => 'Problem solving',
    ];

    foreach ($byrater as $rid => $items) {
        $ru = $rusers[$rid] ?? null;
        $rname = $ru ? (fullname($ru) . ' (' . $ru->username . ')') : ('User ID ' . $rid);

        fputcsv($out, ['Rater', $rname]);
        fputcsv($out, ['Criterion', 'Score']);

        // per-criterion scores & a single comment + total (same as page)
        $critvals = [];
        $comment  = '';
        $total    = 0;
        foreach ($criteria as $ckey => $label) { $critvals[$ckey] = null; }

        foreach ($items as $it) {
            $ckey = (string)$it->criterion;
            if (array_key_exists($ckey, $critvals)) {
                $critvals[$ckey] = is_null($it->score) ? null : (int)$it->score;
                if (!is_null($critvals[$ckey])) { $total += (int)$it->score; }
            }
            if ($comment === '' && !empty($it->comment)) {
                $comment = (string)$it->comment;
            }
        }

        foreach ($criteria as $ckey => $label) {
            $val = is_null($critvals[$ckey]) ? '-' : (string)$critvals[$ckey];
            fputcsv($out, [$label, $val]);
        }
        fputcsv($out, ['Total', (string)$total]);

        // comment
        $comment_oneline = trim(preg_replace('/\s+/', ' ', (string)$comment));
        fputcsv($out, ['Comment', $comment_oneline]);

        // sentiment label (match page’s resolution)
        $sentlabel = get_sentiment_label_for_pair($speid, (int)$rid, (int)$userid, (string)$comment_oneline);
        $sentlabel = ($sentlabel === '') ? '—' : $sentlabel;
        fputcsv($out, ['Sentiment', $sentlabel]);

        // disparity (pair-level) with reason (match page)
        $disp = $DB->get_record('spe_disparity', [
            'speid'   => $speid,
            'raterid' => (int)$rid,
            'rateeid' => (int)$userid
        ], 'isdisparity, commenttext', IGNORE_MISSING);

        if ($disp && (int)$disp->isdisparity === 1) {
            $reason = trim((string)($disp->commenttext ?? ''));
            fputcsv($out, ['Disparity', 'Yes', 'Reason', $reason]);
        } else {
            fputcsv($out, ['Disparity', '—']);
        }

        fputcsv($out, ['']); // spacer
    }

    fclose($out);
    exit;
}

// ---------- MODE B: gradebook export (default if no userid) ----------

// criteria (match gradebook.php display)
$criteria = [
    'effortdocs'    => 'Effort on docs',
    'teamwork'      => 'Teamwork',
    'communication' => 'Communication',
    'management'    => 'Management',
    'problemsolve'  => 'Problem solving',
];

// collect all participants (ratees) similar to gradebook.php
$userids = [];

// received any rating
$list = $DB->get_fieldset_sql(
    "SELECT DISTINCT rateeid FROM {spe_rating} WHERE speid = :s",
    ['s' => $speid]
);
foreach ($list as $uid) { $userids[(int)$uid] = true; }

// anyone who submitted (safety)
$list2 = $DB->get_fieldset_sql(
    "SELECT DISTINCT userid FROM {spe_submission} WHERE speid = :s",
    ['s' => $speid]
);
foreach ($list2 as $uid) { $userids[(int)$uid] = true; }

if (!$userids) { $fail('No participants detected for this activity.'); }

list($uinsql, $uinparams) = $DB->get_in_or_equal(array_keys($userids), SQL_PARAMS_NAMED, 'u');
$users = $DB->get_records_select('user', "id $uinsql", $uinparams, '', 'id, firstname, lastname, username');

// aggregate Σ per criterion for each rateeid (exclude self-ratings)
$params = ['speid' => $speid];
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
    if ($ckey === 'problemsolving' || $ckey === 'problem solving') { $ckey = 'problemsolve'; }
    if (!array_key_exists($ckey, $criteria)) { continue; }
    if (!isset($matrix[$rid])) { $matrix[$rid] = []; }
    $matrix[$rid][$ckey] = (int)$r->sumscore;
}
$rs->close();

// raters count per rateeid (exclude self)
$sqlraters = "SELECT rateeid, COUNT(DISTINCT raterid) AS raters
                FROM {spe_rating}
               WHERE speid = :speid
                 AND raterid <> rateeid
            GROUP BY rateeid";
$raters = $DB->get_records_sql($sqlraters, $params);

// disparity map
$disparity = load_disparity_map_for_rateeids($speid, array_keys($users));

// CSV headers
$filename = 'spe_gradebook.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Cache-Control: private, must-revalidate');
header('Pragma: public');

$out = fopen('php://output', 'w');
if (!$out) { $fail('Cannot open output stream.'); }
fputs($out, "\xEF\xBB\xBF"); // BOM

// header row (match gradebook table)
$head = ['Student'];
foreach ($criteria as $key => $label) { $head[] = $label . ' (Σ)'; }
$head[] = 'Total (Σ)';
$head[] = 'Average per rater';
$head[] = 'Disparity';
fputcsv($out, $head);

// rows
foreach ($users as $uid => $u) {
    $row = [];
    $name = fullname($u) . ' (' . $u->username . ')';
    $row[] = $name;

    $sumtotal = 0;
    foreach ($criteria as $ckey => $_label) {
        $v = isset($matrix[$uid][$ckey]) ? (int)$matrix[$uid][$ckey] : 0;
        $sumtotal += $v;
        $row[] = $v;
    }

    $row[] = $sumtotal;

    $ratercount = isset($raters[$uid]) ? (int)$raters[$uid]->raters : 0;
    $avg = $ratercount > 0 ? round($sumtotal / $ratercount, 2) : '-';
    $row[] = $avg;

    $row[] = !empty($disparity[$uid]) ? 'Yes' : '';

    fputcsv($out, $row);
}

fclose($out);
exit;
