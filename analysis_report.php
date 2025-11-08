<?php

defined('MOODLE_INTERNAL') || define('MOODLE_INTERNAL', true);
require('../../config.php');

$cmid = required_param('id', PARAM_INT);

$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/spe:viewreports', $context);

$PAGE->set_url('/mod/spe/analysis_report.php', ['id' => $cm->id]);
$PAGE->set_title('SPE Analysis Report');
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading('SPE Analysis Report');

// Disparity chip style
echo html_writer::tag('style', '
.spe-chip { display:inline-block; padding:2px 8px; border-radius:999px; font-size:12px; line-height:1.4; }
.spe-chip.disparity { background:#fff3cd; color:#856404; border:1px solid #ffeeba; }
.spe-mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace; font-size:12px; color:#555; }
');

// Helper to build a display name without triggering fullname()
$mkname = function(string $first = '', string $last = ''): string {
    return format_string(trim($first . ' ' . $last));
};

$mgr    = $DB->get_manager();
$params = ['speid' => $cm->instance];

// Load disparities key
$disparities = [];
if ($mgr->table_exists('spe_disparity')) {
    $sqldisp = "SELECT d.raterid, d.rateeid, d.label, d.scoretotal, d.timecreated
                  FROM {spe_disparity} d
                 WHERE d.speid = :speid";
    $drows = $DB->get_records_sql($sqldisp, $params);
    foreach ($drows as $d) {
        $key = $d->raterid . '->' . $d->rateeid;
        $disparities[$key] = [
            'label'      => (string)($d->label ?? ''),
            'scoretotal' => (int)($d->scoretotal ?? 0),
            'time'       => (int)$d->timecreated
        ];
    }
}

// Scores and peer comments
echo html_writer::tag('h3', 'Scores & Comments');

if (!$mgr->table_exists('spe_rating')) {
    echo $OUTPUT->notification('Table <code>spe_rating</code> does not exist. Run plugin upgrade.', 'notifyproblem');
} else {
    $sqlratings = "SELECT r.rateeid,
                          r.raterid,
                          u1.firstname AS rater_first, u1.lastname AS rater_last,
                          u2.firstname AS ratee_first, u2.lastname AS ratee_last,
                          r.criterion, r.score, r.comment, r.timecreated
                     FROM {spe_rating} r
                     JOIN {user} u1 ON u1.id = r.raterid
                     JOIN {user} u2 ON u2.id = r.rateeid
                    WHERE r.speid = :speid
                 ORDER BY r.raterid, r.rateeid, r.criterion";
    $rows = $DB->get_records_sql($sqlratings, $params);

    if (!$rows) {
        echo $OUTPUT->notification('No ratings found.', 'notifywarning');
    } else {
        $byPair = [];
        foreach ($rows as $r) {
            $key = $r->raterid . '->' . $r->rateeid;
            if (!isset($byPair[$key])) {
                $byPair[$key] = [
                    'rater'   => $mkname($r->rater_first, $r->rater_last),
                    'ratee'   => $mkname($r->ratee_first, $r->ratee_last),
                    'scores'  => [],
                    'comment' => $r->comment,
                ];
            }
            $byPair[$key]['scores'][$r->criterion] = (int)$r->score;
            if (!empty($r->comment)) {
                $byPair[$key]['comment'] = $r->comment;
            }
        }

        $table = new html_table();
        $table->head = ['Rater', 'Ratee', 'Avg Score', 'Comment (excerpt)', 'Label'];

        foreach ($byPair as $key => $pair) {
            $avg = !empty($pair['scores'])
                ? (array_sum($pair['scores']) / count($pair['scores']))
                : 0.0;

            $comment = (string)($pair['comment'] ?? '');
            $fullcomment = s($comment);            // fully escaped
            $excerpt = nl2br($fullcomment);

            // Build Disparity
            $disphtml = '-';
            if (isset($disparities[$key])) {
                $d = $disparities[$key];
                $when  = $d['time'] ? userdate($d['time']) : '';
                $meta  = $when ? " <span class='spe-mono'>@ {$when}</span>" : '';
                $disphtml = html_writer::tag(
                    'span',
                    "Disparity: Yes. {$meta}",
                    ['class' => 'spe-chip disparity']
                );
            }

            $table->data[] = [
                s($pair['rater']),
                s($pair['ratee']),
                format_float($avg, 2),
                $excerpt,
                $disphtml
            ];
        }

        echo html_writer::table($table);
    }
}

// Sentiment analysis queue and result
echo html_writer::tag('h3', 'Queued Texts & NLP Results');

if (!$mgr->table_exists('spe_sentiment')) {
    echo $OUTPUT->notification('Table <code>spe_sentiment</code> does not exist. Run plugin upgrade.', 'notifyproblem');
} else {
    $sqlsent = "SELECT s.id, s.raterid, s.rateeid, s.type, s.label, s.sentiment, s.status,
                       s.text, s.timecreated, s.timemodified,
                       ur.firstname AS rater_first, ur.lastname AS rater_last,
                       ue.firstname AS ratee_first, ue.lastname AS ratee_last
                  FROM {spe_sentiment} s
                  JOIN {user} ur ON ur.id = s.raterid
                  JOIN {user} ue ON ue.id = s.rateeid
                 WHERE s.speid = :speid
              ORDER BY s.timecreated DESC, s.id DESC";
    $sentrows = $DB->get_records_sql($sqlsent, $params);

    if (!$sentrows) {
        echo $OUTPUT->notification('No NLP queue entries found for this activity.', 'notifywarning');
    } else {
        $table2 = new html_table();
        $table2->head = ['ID', 'Type', 'Rater', 'Target', 'Status', 'Label', 'Score', 'Excerpt'];

        foreach ($sentrows as $srow) {
            // Map stored type to display label (including selfdesc → self-description)
            $typekey = (string)($srow->type ?? '');
            $typemap = [
                'peer_comment' => 'peer comment',
                'reflection'   => 'reflection',
                'selfdesc'     => 'self-description',
            ];
            $type = ($srow->raterid == $srow->rateeid && $typekey !== 'reflection')
                ? 'self-description'
                : ($typemap[$typekey] ?? str_replace('_', ' ', $typekey));
            $rater  = $mkname($srow->rater_first, $srow->rater_last);
            $ratee  = $mkname($srow->ratee_first, $srow->ratee_last);
            $label  = $srow->label ? s($srow->label) : '-';
            $score  = isset($srow->sentiment) ? format_float((float)$srow->sentiment, 4) : '-';
            $status = s($srow->status);
            $excerpt = s(core_text::substr($srow->text ?? '', 0));

            $table2->data[] = [
                $srow->id,
                s($type),
                s($rater),
                s($ratee),
                $status,
                $label,
                $score,
                $excerpt
            ];
        }

        echo html_writer::table($table2);
    }
}

// Analysis button
$buttons = html_writer::div(
    $OUTPUT->single_button(
        new moodle_url('/mod/spe/analyze_push.php', ['id' => $cm->id, 'sesskey' => sesskey()]),
        'Analyze pending now',
        'get'
    ) . ' ' .
    $OUTPUT->single_button(
        new moodle_url('/mod/spe/view.php', ['id' => $cm->id]),
        'Back to activity',
        'get'
    )
);
echo $buttons;

echo $OUTPUT->footer();
