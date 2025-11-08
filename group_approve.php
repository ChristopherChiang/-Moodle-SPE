<?php
// mod/spe/group_approve.php
//
// Approve Group Scores:
// - Evaluation (from spe_rating): average per-rater total on a 0..20 scale.
// - Sentiment (from spe_sentiment.sentiment): normalized 0..1 (peer_comment only).
// - Combined: 50% evaluation + 50% sentiment => 0..100%, and also saved as total_score (0..20).

require('../../config.php');

$cmid    = required_param('id', PARAM_INT);
$approve = optional_param('approve', 0, PARAM_INT);

$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);

// Must be manager
require_capability('mod/spe:manage', $context);

// Page
$PAGE->set_url('/mod/spe/group_approve.php', ['id' => $cm->id]);
$PAGE->set_title('Approve Group Scores');
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

// All groups
$allgroups = groups_get_all_groups($course->id);

// Map user -> group name
$usergroup = [];
foreach ($allgroups as $g) {
    $members = groups_get_members($g->id, 'u.id, u.firstname, u.lastname, u.username');
    foreach ($members as $u) {
        if (!isset($usergroup[$u->id])) {
            $usergroup[$u->id] = $g->name;
        }
    }
}

// All ratings for this activity
$ratings = $DB->get_records('spe_rating', ['speid' => $cm->instance], 'rateeid, raterid, id');

// Build sums per (ratee <- rater), excluding self-ratings
$received = [];   // [rateeid]['sumfrom'][raterid] => total points
$raterset = [];   // [rateeid][raterid] => true

foreach ($ratings as $r) {
    if ((int)$r->rateeid === (int)$r->raterid) {
        continue;
    }
    if (!isset($received[$r->rateeid]['sumfrom'][$r->raterid])) {
        $received[$r->rateeid]['sumfrom'][$r->raterid] = 0;
    }
    $received[$r->rateeid]['sumfrom'][$r->raterid] += (int)$r->score;
    $raterset[$r->rateeid][$r->raterid] = true;
}

// Compute per-user results: 50% evaluation (ratings) + 50% sentiment (normalized 0..1 from spe_sentiment.sentiment)
$rows = [];
foreach ($received as $uid => $info) {
    $totals = array_values($info['sumfrom'] ?? []);
    $numraters = count($totals);
    if ($numraters === 0) {
        continue;
    }

    // 1) Evaluation from ratings (avg per rater), on a 0..20 scale
    $avgpoints = array_sum($totals) / $numraters; // assumes your rubric max totals 20 per rater

    // 2) Sentiment from spe_sentiment (already normalized 0..1)
    //    Average over peer comments for this ratee in this activity.
    $avgnorm = $DB->get_field_sql("
        SELECT AVG(s.sentiment)
          FROM {spe_sentiment} s
         WHERE s.speid   = :speid
           AND s.rateeid = :uid
           AND s.type    = 'peer_comment'
    ", ['speid' => $cm->instance, 'uid' => $uid]);

    if ($avgnorm === false || $avgnorm === null) {
        // If no sentiment, treat as neutral (you can switch to 0.0 if you prefer)
        $avgnorm = 0.5;
    } else {
        $avgnorm = (float)$avgnorm;
        if ($avgnorm < 0) $avgnorm = 0.0;
        if ($avgnorm > 1) $avgnorm = 1.0;
    }

    // 3) Combine to 0..100, each worth 50%
    //    - evaluation% = (avgpoints / 20) * 100
    //    - sentiment%  = avgnorm * 100
    $combined_0to100 =
        (($avgpoints / 25.0) * 100.0 * 0.5) +
        ($avgnorm * 100.0 * 0.5);

    // Clamp safety
    if ($combined_0to100 < 0)   $combined_0to100 = 0.0;
    if ($combined_0to100 > 100) $combined_0to100 = 100.0;

    // 4) Convert combined% back to your 0..20 “total score”
    //    100% == 20 points  → divide by 5
    $total_score = $combined_0to100 / 4.0;

    // Optional display helpers
    $percent = round($combined_0to100, 1);
    $stars   = round(($percent * 5.0) / 100.0, 2);

    // For compatibility with earlier fields you saved:
    // derive a pseudo 'compound' (-1..1) from normalized (0..1)
    $compound = ($avgnorm * 2.0) - 1.0;

    $rows[$uid] = [
        'userid'      => (int)$uid,
        'group'       => $usergroup[$uid] ?? 'Ungrouped',
        'avgpoints'   => round($avgpoints, 3),      // 0..20 average across raters
        'compound'    => round($compound, 3),       // -1..1, derived from normalized
        'normalized'  => round($avgnorm, 3),        // 0..1 from spe_sentiment.sentiment
        'percent'     => $percent,                  // 0..100 combined
        'stars'       => $stars,                    // 0..5 visual
        'raters'      => $numraters,
        'total_score' => round($total_score, 3)     // 0..20 combined
    ];
}

// Publish to user preferences (used by gradebook.php)
if ($approve && confirm_sesskey()) {
    foreach ($rows as $uid => $d) {
        $prefname = 'mod_spe_groupscore_' . $cm->id;
        set_user_preference($prefname, json_encode($d), $uid);
    }
    redirect(
        new moodle_url('/mod/spe/group_approve.php', ['id' => $cm->id]),
        'Group scores published. They can now be shown on student submission pages.', 1
    );
}

// ----- Render -----
echo $OUTPUT->header();
echo $OUTPUT->heading('Approve Group Scores');

echo html_writer::div(
    'Below are per-student averages based on <strong>received</strong> peer ratings, combined 50% with sentiment. ' .
    'Percent = Combined × 100. Stars = Percent × 5 / 100. ' .
    'Click <em>Publish</em> to save each score as a user preference so it can be shown on student submission pages.',
    'mb-3'
);

if (!empty($rows)) {
    $puburl = new moodle_url('/mod/spe/group_approve.php', ['id' => $cm->id, 'approve' => 1, 'sesskey' => sesskey()]);
    echo html_writer::div(
        html_writer::link($puburl, '✅ Publish group scores', ['class' => 'btn btn-primary mb-3']),
        ''
    );
}

// Table
echo html_writer::start_tag('table', ['class' => 'generaltable']);
echo html_writer::tag('tr',
    html_writer::tag('th', 'User') .
    html_writer::tag('th', 'Group') .
    html_writer::tag('th', 'Raters') .
    html_writer::tag('th', 'Avg points') .
    html_writer::tag('th', 'Percent') .
    html_writer::tag('th', 'Stars / 5')
);

// Names
if ($rows) {
    $uids = array_keys($rows);
    list($in, $params) = $DB->get_in_or_equal($uids, SQL_PARAMS_NAMED);
    $names = $DB->get_records_select('user', "id $in", $params, '', 'id, firstname, lastname, username');

    foreach ($rows as $uid => $d) {
        $u = $names[$uid] ?? null;
        $uname = $u ? fullname($u) . ' (' . s($u->username) . ')' : $uid;

        echo html_writer::tag('tr',
            html_writer::tag('td', $uname) .
            html_writer::tag('td', s($d['group'])) .
            html_writer::tag('td', (string)$d['raters']) .
            html_writer::tag('td', (string)$d['avgpoints']) .
            html_writer::tag('td', $d['percent'] . '%') .
            html_writer::tag('td', $d['stars'] . ' / 5')
        );
    }
}
echo html_writer::end_tag('table');

// Back
$back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
echo html_writer::div(
    html_writer::link($back, '← Back to Instructor dashboard', ['class' => 'btn btn-secondary mt-3'])
);

echo $OUTPUT->footer();
