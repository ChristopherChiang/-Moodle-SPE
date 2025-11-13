<?php

require('../../config.php');

$cmid   = required_param('id', PARAM_INT);
$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/spe:manage', $context);

// Setup Page
$PAGE->set_url('/mod/spe/analyze_push.php', ['id' => $cm->id]);
$PAGE->set_title('SPE — Run Sentiment Analysis');
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');

echo $OUTPUT->header();
echo $OUTPUT->heading('Run sentiment analysis');

// Gather pending items
$pendings = $DB->get_records('spe_sentiment', 
[
    'speid'  => $cm->instance,
    'status' => 'pending'
], 'timecreated ASC', 'id, speid, raterid, rateeid, type, text, timecreated');

if (!$pendings) 
{
    echo $OUTPUT->notification('Nothing to analyze — there are no pending items for this activity.', 'notifyinfo');
    $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
    echo html_writer::div($OUTPUT->single_button($back, '← Back to Instructor', 'get'), 'mt-3');
    echo $OUTPUT->footer();
    exit;
}

// API interaction
require_once($CFG->libdir . '/filelib.php');

function spe_probe_api(string $apiurl): bool 
{
    $curl  = new curl();
    $base  = rtrim($apiurl, '/');
    $probe = preg_replace('#/analyze/?$#', '', $base) . '/openapi.json';

    try 
    {
        $curl->get($probe, ['timeout' => 5]);
        $info = $curl->get_info();
        if (!empty($info['http_code']) && (int)$info['http_code'] === 200) { return true; }
    } catch (Exception $e) { /* ignore */ }

    try 
    {
        $curl->options($base, ['timeout' => 5]);
        $info = $curl->get_info();
        if (!empty($info['http_code']) && (int)$info['http_code'] === 200) { return true; }
    } catch (Exception $e) { /* ignore */ }

    return false;
}

$apiurl   = trim((string)get_config('mod_spe', 'sentiment_url'));
$apitoken = trim((string)get_config('mod_spe', 'sentiment_token'));
if ($apiurl === '') 
{ 
    $apiurl = 'http://127.0.0.1:8000/analyze'; 
}
if (strpos($apiurl, '/analyze') === false) 
{ 
    $apiurl = rtrim($apiurl, '/') . '/analyze'; 
}

if (!spe_probe_api($apiurl)) 
{
    echo $OUTPUT->notification('Sentiment API is not reachable. Please ensure it is running and the URL is correct.', 'notifyproblem');
    $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
    echo html_writer::div($OUTPUT->single_button($back, '← Back to Instructor', 'get'), 'mt-3');
    echo $OUTPUT->footer();
    exit;
}

// Build payload
$SCORE_MIN_DEFAULT = 5;
$SCORE_MAX_DEFAULT = 25;

$items = [];
$byid  = []; 

foreach ($pendings as $row) 
{
    $scoretotal = (int)$DB->get_field_sql(
        "SELECT COALESCE(SUM(score),0)
           FROM {spe_rating}
          WHERE speid = :s AND raterid = :r AND rateeid = :e",
        ['s' => $cm->instance, 'r' => $row->raterid, 'e' => $row->rateeid]
    );

    $items[] = 
    [
        'id'          => (string)$row->id,
        'text'        => (string)$row->text,
        'score_total' => $scoretotal,
        'score_min'   => $SCORE_MIN_DEFAULT,
        'score_max'   => $SCORE_MAX_DEFAULT,
    ];

    $byid[(int)$row->id] = (object)
    [
        'row'        => $row,
        'scoretotal' => $scoretotal
    ];
}

if (count($items) > 2000) 
{ 
    $items = array_slice($items, 0, 2000); 
}

// Cannonical JSON payload
$payload = json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);

require_once(__DIR__ . '/ca_helpers.php');
$path      = '/analyze';
$caheaders = spe_ca_build_request_headers($path, $payload);

// API request
$curl    = new curl();
$headers = ['Content-Type: application/json'];
if ($apitoken !== '') 
{ 
    $headers[] = 'X-API-Token: ' . $apitoken; 
}
foreach ($caheaders as $k => $v) 
{ 
    $headers[] = $k . ': ' . $v; 
}

try 
{
    $resp = $curl->post($apiurl, $payload, [
        'CURLOPT_HTTPHEADER' => $headers,
        'timeout'            => 60,
        'CURLOPT_TIMEOUT'    => 60,
        'RETURNTRANSFER'     => true,
        'HEADER'             => true,
    ]);

    $info        = $curl->get_info();
    $http        = (int)($info['http_code'] ?? 0);
    $header_size = (int)($info['header_size'] ?? 0);
    $raw_headers = substr($resp, 0, $header_size);
    $body        = substr($resp, $header_size);

    // Verify server response signature
    $respheaders = [];
    foreach (preg_split('/\r\n/', $raw_headers) as $line) 
    {
        if (strpos($line, ':') !== false) 
        {
            [$k, $v] = array_map('trim', explode(':', $line, 2));
            $respheaders[strtolower($k)][] = $v;
        }
    }
    spe_ca_verify_server_response($path, $body, $respheaders);

    if ($http >= 400 || $http === 0) 
    {
        echo $OUTPUT->notification('Sentiment API returned HTTP ' . $http . '.', 'notifyproblem');
        $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
        echo html_writer::div($OUTPUT->single_button($back, '← Back to Instructor', 'get'), 'mt-3');
        echo $OUTPUT->footer();
        exit;
    }

    $resp = $body;
} catch (Exception $e) {
    echo $OUTPUT->notification('Error contacting Sentiment API: ' . s($e->getMessage()), 'notifyproblem');
    $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
    echo html_writer::div($OUTPUT->single_button($back, '← Back to Instructor', 'get'), 'mt-3');
    echo $OUTPUT->footer();
    exit;
}

// Parse response
$data = json_decode($resp);
if ($data === null || (json_last_error() !== JSON_ERROR_NONE)) 
{
    echo $OUTPUT->notification('Unexpected (non-JSON) response from Sentiment API.', 'notifyproblem');
    echo html_writer::tag('pre', s(substr($resp, 0, 400)));
    $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
    echo html_writer::div($OUTPUT->single_button($back, '← Back to Instructor', 'get'), 'mt-3');
    echo $OUTPUT->footer();
    exit;
}

if (is_object($data) && property_exists($data, 'ok') && $data->ok === false) 
{
    echo $OUTPUT->notification('Sentiment API rejected the batch (likely token mismatch).', 'notifyproblem');
    $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
    echo html_writer::div($OUTPUT->single_button($back, '← Back to Instructor', 'get'), 'mt-3');
    echo $OUTPUT->footer();
    exit;
}

if (!isset($data->results) || !is_array($data->results)) 
{
    echo $OUTPUT->notification('Unexpected response format from Sentiment API.', 'notifyproblem');
    $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
    echo html_writer::div($OUTPUT->single_button($back, '← Back to Instructor', 'get'), 'mt-3');
    echo $OUTPUT->footer();
    exit;
}

// Persist results
$processedids = [];
$mgr = $DB->get_manager();
$ins_count = 0; $upd_count = 0;

foreach ($data->results as $res) 
{
    $id = (int)($res->id ?? 0);
    if (!$id) 
    { 
        continue; 
    }

    if (!isset($byid[$id])) 
    { 
        continue; 
    }
    $cache = $byid[$id];
    /** @var stdClass $sentrow */
    $sentrow = $cache->row;
    $scoretotal = (int)$cache->scoretotal;

    if (!$row = $DB->get_record('spe_sentiment', ['id' => $id, 'speid' => $cm->instance])) 
    {
        continue;
    }

    // Persist sentiment outputs
    $compound          = isset($res->compound) ? (float)$res->compound : 0.0;
    $label             = isset($res->label) ? (string)$res->label : '-';
    $row->sentiment    = $compound;
    $row->label        = $label;
    $row->status       = 'done';
    $row->timemodified = time();
    $DB->update_record('spe_sentiment', $row);

    $processedids[] = $id;

    // If disparity table exists, update it based on API result

    if ($mgr->table_exists('spe_disparity')) 
    {
        $isdisparity = !empty($res->disparity) ? 1 : 0;
        $reason      = isset($res->disparity_reason) ? (string)$res->disparity_reason : '';

        $commenttext = $isdisparity ? $reason : '';

        $existing = $DB->get_record('spe_disparity', [
            'speid'   => $cm->instance,
            'raterid' => (int)$sentrow->raterid,
            'rateeid' => (int)$sentrow->rateeid
        ]);

        $rec = (object)
        [
            'speid'       => $cm->instance,
            'raterid'     => (int)$sentrow->raterid,
            'rateeid'     => (int)$sentrow->rateeid,
            'label'       => $label,
            'scoretotal'  => $scoretotal,
            'commenttext' => $commenttext,   
            'isdisparity' => $isdisparity,
            'timecreated' => time()
        ];

        if ($existing) 
        {
            $rec->id = $existing->id;
            $DB->update_record('spe_disparity', $rec);
            $upd_count++;
        } 
        else 
        {
            $DB->insert_record('spe_disparity', $rec);
            $ins_count++;
        }
    }


}

if (!$processedids) 
{
    echo $OUTPUT->notification('No items were processed.', 'notifyinfo');
    $back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
    echo html_writer::div($OUTPUT->single_button($back, '← Back to Instructor', 'get'), 'mt-3');
    echo $OUTPUT->footer();
    exit;
}

// Read back processed items for display
list($insql, $inparams) = $DB->get_in_or_equal($processedids, SQL_PARAMS_NAMED, 's');
$rows = $DB->get_records_select('spe_sentiment', "id $insql", $inparams, 'type, raterid, rateeid, id');

$userids = [];
foreach ($rows as $r) 
{ $userids[$r->raterid] = true; $userids[$r->rateeid] = true; }
$users = [];
if ($userids) 
{
    list($uinsql, $uinparams) = $DB->get_in_or_equal(array_keys($userids), SQL_PARAMS_NAMED, 'u');
    $users = $DB->get_records_select('user', "id $uinsql", $uinparams, '', 'id, firstname, lastname, username');
}

// Split reflections and comments
$reflections  = [];
$selfdescs    = [];
$peercomments = [];
foreach ($rows as $r) 
{
    if ($r->type === 'reflection') 
    {
        $reflections[] = $r;
    } 
    elseif ($r->type === 'selfdesc') 
    {
        $selfdescs[] = $r;
    } 
    else 
    {
        $peercomments[] = $r;
    }
}


// Summary line
$summary = 'Processed ' . count($processedids) . ' items — ' .
           count($reflections) . ' reflection(s), ' .
           count($selfdescs) . ' self-description(s), ' .
           count($peercomments) . ' peer comment(s).';


if ($mgr->table_exists('spe_disparity')) 
{
    $summary .= ' | Disparity rows: ' . $ins_count . ' inserted, ' . $upd_count . ' updated.';
}

echo html_writer::div($summary, 'alert alert-success');

$badge = function (string $label): string 
{
    $style = 'background:#6c757d;';
    if ($label === 'positive') $style = 'background:#1a7f37;';
    if ($label === 'negative') $style = 'background:#b42318;';
    if ($label === 'toxic')    $style = 'background:#000;';
    return '<span style="color:#fff;padding:2px 8px;border-radius:12px;font-size:12px;'.$style.'">'.s($label).'</span>';
};

// Table renderer
$render_table = function(string $title, array $data) use ($users, $badge) 
{
    echo html_writer::tag('h3', $title);
    if (!$data) 
    { 
        echo html_writer::div('None.', 'muted'); 
        return; 
    }

    echo html_writer::start_tag('table', ['class' => 'generaltable']);
    echo html_writer::tag('tr',
        html_writer::tag('th', 'Rater') .
        html_writer::tag('th', 'Target') .
        html_writer::tag('th', 'Type') .
        html_writer::tag('th', 'Label') .
        html_writer::tag('th', 'Score (compound)') .
        html_writer::tag('th', 'Excerpt')
    );

    foreach ($data as $r) 
    {
        $rater = $users[$r->raterid] ?? null;
        $ratee = $users[$r->rateeid] ?? null;
        $rname = $rater ? fullname($rater) . " ({$rater->username})" : (string)$r->raterid;
        $tname = $ratee ? fullname($ratee) . " ({$ratee->username})" : (string)$r->rateeid;

        $typemap = ['peer_comment' => 'peer comment', 'reflection' => 'reflection', 'selfdesc' => 'self-description'];
        $typekey = (string)($r->type ?? '');
        $type = ($r->raterid == $r->rateeid && $typekey !== 'reflection')
            ? 'self-description'
            : ($typemap[$typekey] ?? str_replace('_', ' ', $typekey));
            
        $compound = sprintf('%.3f', (float)$r->sentiment);
        $txt      = (string)($r->text ?? '');
        $excerpt  = s(core_text::substr(clean_text($txt), 0, 120)) . (core_text::strlen($txt) > 120 ? '…' : '');

        echo html_writer::tag('tr',
            html_writer::tag('td', $rname) .
            html_writer::tag('td', $tname) .
            html_writer::tag('td', s($type)) .
            html_writer::tag('td', $badge((string)$r->label)) .
            html_writer::tag('td', $compound) .
            html_writer::tag('td', $excerpt)
        );
    }
    echo html_writer::end_tag('table');
    echo html_writer::empty_tag('hr');
};

$render_table('Reflection', $reflections);
$render_table('Self-descriptions', $selfdescs);
$render_table('Peer comments', $peercomments);


// Back button
$back = new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]);
echo html_writer::div($OUTPUT->single_button($back, '← Back to Instructor', 'get'), 'mt-3');

echo $OUTPUT->footer();
