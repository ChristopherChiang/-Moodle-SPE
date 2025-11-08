<?php
require('../../config.php');
require_once($CFG->libdir . '/pdflib.php');

$cmid     = required_param('id', PARAM_INT);
$download = optional_param('download', 0, PARAM_BOOL);
$userid   = optional_param('userid', 0, PARAM_INT); // if set => grade_detail export

$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);

// Only admin or instructors can export PDF
$allowedcaps = ['mod/spe:viewresults', 'mod/spe:viewreports', 'mod/spe:manage'];
if (!has_any_capability($allowedcaps, $context)) {
    require_capability('mod/spe:viewresults', $context);
}

// Back target
$returnurl = optional_param('returnurl', '', PARAM_LOCALURL);
if (!$returnurl) {
    $ref = get_local_referer(false);
    $returnurl = $ref ? $ref : (new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]))->out(false);
}

// If userid isn't passed explicitly, try to infer from returnurl (when coming from grade_detail.php)
if (!$userid && $returnurl) {
    $parts = parse_url($returnurl);
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $qs);
        if (!empty($qs['userid']) && ctype_digit((string)$qs['userid'])) {
            $userid = (int)$qs['userid'];
        }
    }
}

// Page header mode
if (!$download) {
    $params = ['id' => $cm->id, 'returnurl' => $returnurl] + ($userid ? ['userid' => $userid] : []);
    $PAGE->set_url('/mod/spe/export_pdf.php', $params);
    $PAGE->set_context($context);
    $PAGE->set_title('SPE — Export PDF');
    $PAGE->set_heading(format_string($course->fullname));

    echo $OUTPUT->header();
    echo $OUTPUT->heading('Analysis Results & Exports', 2);

    echo html_writer::div(
        html_writer::link(new moodle_url($returnurl), '← Back'),
        '',
        ['style' => 'margin-bottom:12px;']
    );

    $dlurl = new moodle_url('/mod/spe/export_pdf.php', ['id' => $cm->id, 'download' => 1] + ($userid ? ['userid' => $userid] : []));
    echo html_writer::div(html_writer::link($dlurl, 'Start PDF download'), '', ['style' => 'margin-top:12px;']);
    echo html_writer::tag('script', "window.location.href = " . json_encode($dlurl->out(false)) . ";");

    echo $OUTPUT->footer();
    exit;
}

// -------------------- Download mode --------------------
if (class_exists('\core\session\manager')) { \core\session\manager::write_close(); }
while (ob_get_level()) { ob_end_clean(); }
ignore_user_abort(true);
raise_memory_limit(MEMORY_EXTRA);
@set_time_limit(0);

$speid = (int)$cm->instance;

$fail = function(string $msg) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $msg;
    exit;
};

// ===== Shared helpers =====

// Gradebook criteria labels (must match gradebook.php)
$CRITERIA = [
    'effortdocs'    => 'Effort on docs',
    'teamwork'      => 'Teamwork',
    'communication' => 'Communication',
    'management'    => 'Management',
    'problemsolve'  => 'Problem solving',
];

// Load disparity presence per rateeid
function spe_load_disparity_map_for_rateeids(int $speid, array $rateeids): array {
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

// Sentiment label getter (mirrors grade_detail.php logic)
function spe_get_sentiment_label_for_pair(int $speid, int $raterid, int $rateeid, string $comment): string {
    global $DB;
    $mgr = $DB->get_manager();
    if (!$mgr->table_exists('spe_sentiment')) return '';
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

// ===== MODE 1: grade_detail export (if $userid > 0) =====
if ($userid > 0) {
    // Pull all peer ratings for this ratee (exclude self)
    $rows = $DB->get_records_sql("
        SELECT r.id, r.raterid, r.criterion, r.score, r.comment, r.timecreated
          FROM {spe_rating} r
         WHERE r.speid = :speid
           AND r.rateeid = :rateeid
           AND r.raterid <> r.rateeid
      ORDER BY r.raterid, r.timecreated ASC
    ", ['speid' => $speid, 'rateeid' => $userid]);

    if (!$rows) { $fail('No peer ratings for this student yet.'); }

    // Group by rater
    $byrater = [];
    $raterids = [];
    foreach ($rows as $r) {
        $rid = (int)$r->raterid;
        $byrater[$rid][] = $r;
        $raterids[$rid] = true;
    }

    $rusers = [];
    if ($raterids) {
        list($insql, $inparams) = $DB->get_in_or_equal(array_keys($raterids), SQL_PARAMS_NAMED, 'ru');
        $rusers = $DB->get_records_select('user', "id $insql", $inparams, '', 'id, firstname, lastname, username');
    }

    // PDF init
    $pdf = new pdf('P', 'mm', 'A4');
    $pdf->SetCreator('Moodle SPE');
    $pdf->SetAuthor('Moodle SPE Module');
    $pdf->SetTitle('SPE — Ratings Detail');
    $pdf->SetMargins(14, 12, 14);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();

    $ratee = core_user::get_user($userid, 'id,firstname,lastname,username', IGNORE_MISSING);
    $ratee_name = $ratee ? (fullname($ratee) . ' (' . $ratee->username . ')') : (string)$userid;

    // Title (mirrors page)
    $pdf->SetFont('helvetica', 'B', 13);
    $pdf->Cell(0, 8, 'Ratings received by: ' . $ratee_name, 0, 1);
    $pdf->Ln(1.5);

    // Column widths for the small Criterion/Score table
    $wCrit = 90; // Criterion
    $wScore = 30; // Score

    foreach ($byrater as $rid => $items) {
        $ru = $rusers[$rid] ?? null;
        $rname = $ru ? (fullname($ru) . ' (' . $ru->username . ')') : ('User ID ' . $rid);

        // Rater heading
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 6, 'Rater: ' . $rname, 0, 1);
        $pdf->Ln(0.5);

        // Build per-criterion scores & single comment + total (like page)
        $critvals = [];
        $comment  = '';
        $total    = 0;
        foreach ($CRITERIA as $ckey => $_label) { $critvals[$ckey] = null; }
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

        // Small table: Criterion | Score
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell($wCrit, 7, 'Criterion', 1);
        $pdf->Cell($wScore, 7, 'Score', 1, 1, 'C');
        $pdf->SetFont('helvetica', '', 9);

        foreach ($CRITERIA as $ckey => $label) {
            $val = is_null($critvals[$ckey]) ? '-' : (string)$critvals[$ckey];
            $pdf->Cell($wCrit, 6.5, $label, 1);
            $pdf->Cell($wScore, 6.5, $val, 1, 1, 'C');
        }
        // Total row
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->Cell($wCrit, 7, 'Total', 1);
        $pdf->Cell($wScore, 7, (string)$total, 1, 1, 'C');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Ln(1.2);

        // Comment (single)
        $pdf->SetFont('helvetica', 'B', 9.5);
        $pdf->Cell(0, 5.5, 'Comment:', 0, 1);
        $pdf->SetFont('helvetica', '', 9);
        $comment_oneline = trim(preg_replace('/\s+/', ' ', (string)$comment));
        if ($comment_oneline === '') { $comment_oneline = '—'; }
        $pdf->MultiCell(0, 5.5, $comment_oneline, 1, 'L', false, 1);
        $pdf->Ln(1);

        // Sentiment & Disparity line (mirrors page semantics)
        $sentlabel = spe_get_sentiment_label_for_pair($speid, (int)$rid, (int)$userid, (string)$comment_oneline);
        $sentlabel = ($sentlabel === '') ? '—' : $sentlabel;

        $disp = $DB->get_record('spe_disparity', [
            'speid'   => $speid,
            'raterid' => (int)$rid,
            'rateeid' => (int)$userid
        ], 'isdisparity, commenttext', IGNORE_MISSING);

        $pdf->SetFont('helvetica', '', 9.5);
        $line = 'Sentiment: ' . $sentlabel;
        if ($disp && (int)$disp->isdisparity === 1) {
            $reason = trim((string)($disp->commenttext ?? ''));
            $line .= '    |    Disparity: Yes' . ($reason !== '' ? '.' . $reason : '');
        } else {
            $line .= '    |    Disparity: —';
        }
        $pdf->MultiCell(0, 5.5, $line, 0, 'L', false, 1);

        // Separator
        $pdf->Ln(1.5);
        $pdf->Cell(0, 0, '', 'T', 1); // horizontal rule
        $pdf->Ln(2.0);
    }

    header('Cache-Control: private, must-revalidate');
    header('Pragma: public');
    $pdf->Output('spe_gradedetail.pdf', 'D');
    exit;
}

// ===== MODE 2: gradebook export (default if no userid) =====

// Build the same dataset as gradebook.php
// 1) Collect participants
$userids = [];

// ratees with any received rating
$list = $DB->get_fieldset_sql(
    "SELECT DISTINCT rateeid FROM {spe_rating} WHERE speid = :s",
    ['s' => $speid]
);
foreach ($list as $uid) { $userids[(int)$uid] = true; }

// plus anyone who submitted (safety)
$list2 = $DB->get_fieldset_sql(
    "SELECT DISTINCT userid FROM {spe_submission} WHERE speid = :s",
    ['s' => $speid]
);
foreach ($list2 as $uid) { $userids[(int)$uid] = true; }

if (!$userids) { $fail('No participants detected for this activity.'); }

list($uinsql, $uinparams) = $DB->get_in_or_equal(array_keys($userids), SQL_PARAMS_NAMED, 'u');
$users = $DB->get_records_select('user', "id $uinsql", $uinparams, '', 'id, firstname, lastname, username');

// 2) Aggregate Σ per criterion per rateeid (exclude self)
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
    if (!array_key_exists($ckey, $CRITERIA)) { continue; }
    if (!isset($matrix[$rid])) { $matrix[$rid] = []; }
    $matrix[$rid][$ckey] = (int)$r->sumscore;
}
$rs->close();

// 3) Count distinct raters per rateeid (exclude self)
$sqlraters = "SELECT rateeid, COUNT(DISTINCT raterid) AS raters
                FROM {spe_rating}
               WHERE speid = :speid
                 AND raterid <> rateeid
            GROUP BY rateeid";
$raters = $DB->get_records_sql($sqlraters, $params);

// 4) Disparity map
$disparity = spe_load_disparity_map_for_rateeids($speid, array_keys($users));

// ---- Render PDF table like the Grade book page ----
$pdf = new pdf('L', 'mm', 'A4');
$pdf->SetCreator('Moodle SPE');
$pdf->SetAuthor('Moodle SPE Module');
$pdf->SetTitle('SPE — Grade book');
$pdf->SetMargins(12, 12, 12);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();

$pdf->SetFont('helvetica', 'B', 14);
$pdf->Cell(0, 8, 'SPE — Grade book', 0, 1, 'C');
$pdf->Ln(2);
$pdf->SetFont('helvetica', '', 9);

// Column widths (Student + 5 criteria + Total + Avg + Disparity)
$usableW = $pdf->getPageWidth() - $pdf->getMargins()['left'] - $pdf->getMargins()['right'];
$ratios = [
    'student'=>0.26,
    'c1'     =>0.08,
    'c2'     =>0.08,
    'c3'     =>0.09,
    'c4'     =>0.09,
    'c5'     =>0.10,
    'total'  =>0.08,
    'avg'    =>0.11,
    'disp'   =>0.11,
];
$sumr = array_sum($ratios);
foreach ($ratios as $k=>$v) { $ratios[$k] = $v / $sumr; }
$w = [];
foreach ($ratios as $k=>$v) { $w[$k] = round($usableW * $v, 2); }

// Header row
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell($w['student'], 7, 'Student', 1);
$labels = array_values($CRITERIA);
$pdf->Cell($w['c1'], 7, $labels[0], 1, 0, 'C');
$pdf->Cell($w['c2'], 7, $labels[1], 1, 0, 'C');
$pdf->Cell($w['c3'], 7, $labels[2], 1, 0, 'C');
$pdf->Cell($w['c4'], 7, $labels[3], 1, 0, 'C');
$pdf->Cell($w['c5'], 7, $labels[4], 1, 0, 'C');
$pdf->Cell($w['total'], 7, 'Total', 1, 0, 'C');
$pdf->Cell($w['avg'],   7, 'Average per rater', 1, 0, 'C');
$pdf->Cell($w['disp'],  7, 'Disparity', 1, 1, 'C');
$pdf->SetFont('helvetica', '', 9);

// Rows
$rowH = 6.5;
foreach ($users as $uid => $u) {
    $name = fullname($u) . ' (' . $u->username . ')';

    $vals = [];
    $sumtotal = 0;
    // Using the same order as $CRITERIA
    $i = 0;
    foreach ($CRITERIA as $ckey => $_label) {
        $v = isset($matrix[$uid][$ckey]) ? (int)$matrix[$uid][$ckey] : 0;
        $sumtotal += $v;
        $vals[$i++] = $v;
    }
    $ratercount = isset($raters[$uid]) ? (int)$raters[$uid]->raters : 0;
    $avg = $ratercount > 0 ? round($sumtotal / $ratercount, 2) : '-';
    $disp = !empty($disparity[$uid]) ? 'Yes' : '';

    // Row cells
    $pdf->Cell($w['student'], $rowH, $name, 1);
    $pdf->Cell($w['c1'], $rowH, (string)$vals[0], 1, 0, 'C');
    $pdf->Cell($w['c2'], $rowH, (string)$vals[1], 1, 0, 'C');
    $pdf->Cell($w['c3'], $rowH, (string)$vals[2], 1, 0, 'C');
    $pdf->Cell($w['c4'], $rowH, (string)$vals[3], 1, 0, 'C');
    $pdf->Cell($w['c5'], $rowH, (string)$vals[4], 1, 0, 'C');
    $pdf->Cell($w['total'], $rowH, (string)$sumtotal, 1, 0, 'C');
    $pdf->Cell($w['avg'],   $rowH, is_string($avg) ? $avg : sprintf('%.2f', $avg), 1, 0, 'C');
    $pdf->Cell($w['disp'],  $rowH, $disp, 1, 1, 'C');
}

header('Cache-Control: private, must-revalidate');
header('Pragma: public');
$pdf->Output('spe_gradebook.pdf', 'D');
exit;
