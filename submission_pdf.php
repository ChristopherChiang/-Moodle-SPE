<?php
require('../../config.php');
require_once($CFG->libdir.'/pdflib.php');

$cmid   = required_param('id', PARAM_INT);
$userid = optional_param('userid', 0, PARAM_INT);

$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);

$canmanage = has_capability('mod/spe:manage', $context);
if (!$userid) { $userid = $USER->id; }
if (!$canmanage && (int)$userid !== (int)$USER->id) {
    print_error('nopermissions', 'error', '', 'download this PDF');
}

// Fetch data
$u           = $DB->get_record('user', ['id' => $userid], 'id,firstname,lastname,username', MUST_EXIST);
$spe         = $DB->get_record('spe',  ['id' => $cm->instance], '*', IGNORE_MISSING);
$submission  = $DB->get_record('spe_submission', ['speid' => $cm->instance, 'userid' => $userid], '*', IGNORE_MISSING);

// ratings GIVEN by this user (raterid = $userid)
$ratings = $DB->get_records('spe_rating',
    ['speid' => $cm->instance, 'raterid' => $userid],
    'rateeid, id',
    'id, rateeid, criterion, score, comment'
);

// Close session and clear buffer
while (ob_get_level()) { ob_end_clean(); }
if (class_exists('\core\session\manager')) { \core\session\manager::write_close(); }

// PDF
$aname    = $spe ? format_string($spe->name) : format_string($cm->name);
$filename = clean_filename("Self_and_peer_evaluation.pdf");

$pdf = new pdf();
$pdf->SetCreator('Moodle SPE');
$pdf->SetAuthor(fullname($u));
$pdf->SetTitle($aname);
$pdf->AddPage();

$pdf->SetFont('helvetica', 'B', 16);
$pdf->Cell(0, 10, $aname, 0, 1, 'L');
$pdf->Ln(2);
$pdf->SetFont('helvetica', '', 11);
$pdf->Cell(0, 7, 'Student: '.fullname($u).' ('.$u->username.')', 0, 1, 'L');

// Keep "Last modified" at top, but DO NOT print reflection here (printed later under self).
if ($submission) {
    $when = userdate($submission->timemodified ?: $submission->timecreated);
    $pdf->Cell(0, 7, 'Last modified: '.$when, 0, 1, 'L');
    $pdf->Ln(2);
} else {
    $pdf->Ln(2);
}

$pdf->SetFont('', 'B', 12);
$pdf->Cell(0, 7, 'Ratings given', 0, 1, 'L');
$pdf->SetFont('', '', 11);

if ($ratings) {
    // Prefetch names for all distinct ratee IDs.
    $ids = array_unique(array_map(function($r){ return (int)$r->rateeid; }, $ratings));
    $names = [];
    if (!empty($ids)) {
        list($in, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $names = $DB->get_records_select('user', "id $in", $params, '', 'id, firstname, lastname');
    }

    // Group rating rows by ratee.
    $byratee = [];
    foreach ($ratings as $r) {
        $byratee[(int)$r->rateeid][] = $r;
    }

    // --------- NEW RENDERING ORDER ----------
    // 1) SELF: scores → comments → reflection
    // 2) OTHERS (alphabetical): scores → comments

    // Name resolver
    $name_of = function(int $uid) use ($names) {
        return isset($names[$uid]) ? fullname($names[$uid]) : "User ID $uid";
    };

    // Helper: render scores list
    $render_scores = function(array $items) use ($pdf) {
        $pdf->SetFont('', '', 11);
        foreach ($items as $r) {
            $crit  = isset($r->criterion) ? (string)$r->criterion : '';
            $score = isset($r->score) ? (int)$r->score : 0;
            $pdf->MultiCell(0, 6, "• {$crit}: {$score}", 0, 'L');
        }
    };

    // Helper: render unique non-empty comments (deduped)
    $render_comments = function(array $items) use ($pdf) {
        $seen = [];
        $uniqcomments = [];
        foreach ($items as $r) {
            $c = isset($r->comment) ? trim($r->comment) : '';
            if ($c === '') { continue; }
            $key = trim(preg_replace('/\s+/', ' ', core_text::strtolower($c)));
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $uniqcomments[] = $c;
            }
        }
        if (!empty($uniqcomments)) {
            $pdf->Ln(1);
            $pdf->SetFont('', 'B', 11);
            $pdf->Cell(0, 6, 'Comments', 0, 1, 'L');
            $pdf->SetFont('', '', 11);
            foreach ($uniqcomments as $c) {
                $pdf->MultiCell(0, 6, clean_text($c), 0, 'L');
                $pdf->Ln(1);
            }
        }
    };

    // --- 1) SELF section ---
    if (!empty($byratee[$userid])) {
        $selfitems = $byratee[$userid];

        $pdf->SetFont('', 'B', 11);
        $pdf->Cell(0, 7, 'Ratee: '.fullname($u).' (Self)', 0, 1, 'L');

        // Scores
        $render_scores($selfitems);

        // Comments
        $render_comments($selfitems);

        // Reflection (only for self and if provided)
        if ($submission && trim((string)$submission->reflection) !== '') {
            $pdf->Ln(1);
            $pdf->SetFont('', 'B', 11);
            $pdf->Cell(0, 6, 'Reflection', 0, 1, 'L');
            $pdf->SetFont('', '', 11);
            $pdf->MultiCell(0, 6, format_string($submission->reflection, true), 0, 'L');
        }

        $pdf->Ln(2); // spacer
    }

    // --- 2) OTHER ratees (alphabetical by fullname) ---
    $otherids = array_diff(array_keys($byratee), [$userid]);
    usort($otherids, function($a, $b) use ($name_of) {
        return strcasecmp($name_of((int)$a), $name_of((int)$b));
    });

    foreach ($otherids as $rateeid) {
        $items = $byratee[$rateeid];

        $pdf->SetFont('', 'B', 11);
        $pdf->Cell(0, 7, 'Ratee: '.$name_of((int)$rateeid), 0, 1, 'L');

        // Scores
        $render_scores($items);

        // Comments
        $render_comments($items);

        $pdf->Ln(2); // spacer
    }

} else {
    $pdf->Cell(0, 6, 'No ratings found.', 0, 1, 'L');
}

$pdf->Output($filename, 'D');
exit;
