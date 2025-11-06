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
$u   = $DB->get_record('user', ['id' => $userid], 'id,firstname,lastname,username', MUST_EXIST);
$spe = $DB->get_record('spe',  ['id' => $cm->instance], '*', IGNORE_MISSING);
$submission = $DB->get_record('spe_submission', ['speid' => $cm->instance, 'userid' => $userid], '*', IGNORE_MISSING);
$ratings    = $DB->get_records('spe_rating',    ['speid' => $cm->instance, 'raterid' => $userid], 'rateeid, id');

// Close session and clear buffer
while (ob_get_level()) { ob_end_clean(); }
if (class_exists('\core\session\manager')) { \core\session\manager::write_close(); }

// PDF
$aname    = $spe ? format_string($spe->name) : format_string($cm->name);
$filename = clean_filename("SPE_submission_{$userid}.pdf");

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

if ($submission) {
    $when = userdate($submission->timemodified ?: $submission->timecreated);
    $pdf->Cell(0, 7, 'Last modified: '.$when, 0, 1, 'L');
    $pdf->Ln(2);

    $pdf->SetFont('', 'B', 12);
    $pdf->Cell(0, 7, 'Reflection', 0, 1, 'L');
    $pdf->SetFont('', '', 11);
    $pdf->MultiCell(0, 6, format_string($submission->reflection, true), 0, 'L');
    $pdf->Ln(4);
} else {
    $pdf->Ln(2);
    $pdf->SetFont('', '', 11);
    $pdf->Cell(0, 7, 'No submission found.', 0, 1, 'L');
}

$pdf->SetFont('', 'B', 12);
$pdf->Cell(0, 7, 'Ratings given', 0, 1, 'L');
$pdf->SetFont('', '', 11);

if ($ratings) {
    $ids = array_unique(array_map(fn($r) => $r->rateeid, $ratings));
    $names = [];
    if ($ids) {
        list($in, $params) = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $names = $DB->get_records_select('user', "id $in", $params, '', 'id, firstname, lastname');
    }

    foreach ($ratings as $r) {
        $rname = isset($names[$r->rateeid]) ? fullname($names[$r->rateeid]) : $r->rateeid;
        $line  = "Ratee: $rname | Criterion: {$r->criterion} | Score: ".(int)$r->score;
        $pdf->MultiCell(0, 6, $line, 0, 'L');
        if (!empty($r->comment)) {
            $pdf->MultiCell(0, 6, "Comment: ".$r->comment, 0, 'L');
        }
        $pdf->Ln(1);
    }
} else {
    $pdf->Cell(0, 6, 'No ratings found.', 0, 1, 'L');
}

$pdf->Output($filename, 'D');
exit;
