<?php
require('../../config.php');

$cmid   = required_param('id', PARAM_INT);  // course module id
$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

// Set Page
require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);

require_capability('mod/spe:manage', $context);

$PAGE->set_url('/mod/spe/uploadcsv.php', ['id' => $cm->id]);
$PAGE->set_title('Upload Student CSV');
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading('Upload Student CSV');

// Show form
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo html_writer::tag('p',
        'CSV headers expected: idnumber,username,email,teamname (at least one of idnumber/username/email must be present).');

    echo html_writer::start_tag('form', [
        'method'  => 'post',
        'enctype' => 'multipart/form-data',
        'action'  => new moodle_url('/mod/spe/uploadcsv.php', ['id' => $cm->id])
    ]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::start_tag('p');
    echo html_writer::tag('label', 'Select CSV file: ');
    echo html_writer::empty_tag('input', ['type' => 'file', 'name' => 'csvfile', 'accept' => '.csv']);
    echo html_writer::end_tag('p');

    // Create Moodle groups by teamname
    echo html_writer::start_tag('p');
    echo html_writer::empty_tag('input', ['type' => 'checkbox', 'name' => 'creategroups', 'value' => '1', 'id' => 'creategroups']);
    echo html_writer::tag('label', ' Create Moodle groups by teamname', ['for' => 'creategroups']);
    echo html_writer::end_tag('p');

    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Upload & Process', 'class' => 'btn btn-primary']);
    echo html_writer::end_tag('form');

    echo $OUTPUT->footer();
    exit;
}

// Handle POST
require_sesskey();

if (empty($_FILES['csvfile']['tmp_name'])) {
    echo $OUTPUT->notification('No file uploaded.', 'notifyproblem');
    echo $OUTPUT->continue_button(new moodle_url('/mod/spe/view.php', ['id' => $cm->id]));
    echo $OUTPUT->footer();
    exit;
}

$creategroups = !empty($_POST['creategroups']);
$tmp = $_FILES['csvfile']['tmp_name'];
$handle = fopen($tmp, 'r');
if ($handle === false) {
    echo $OUTPUT->notification('Could not open uploaded file.', 'notifyproblem');
    echo $OUTPUT->continue_button(new moodle_url('/mod/spe/view.php', ['id' => $cm->id]));
    echo $OUTPUT->footer();
    exit;
}

// Get instance row
$spe = $DB->get_record('spe', ['id' => $cm->instance], '*', MUST_EXIST);

$line = 0; $created = 0; $updated = 0; $skipped = 0; $errors = [];
$headers = [];

while (($row = fgetcsv($handle)) !== false) {
    $line++;
    if ($line === 1) { 
        $headers = array_map('strtolower', array_map('trim', $row));
        continue;
    }
    // Skip blank rows
    if (count(array_filter($row, fn($v)=>trim((string)$v) !== '')) === 0) { continue; }

    $data = [];
    foreach ($headers as $i => $h) { $data[$h] = $row[$i] ?? ''; }

    $idnumber = trim($data['idnumber'] ?? '');
    $username = trim($data['username'] ?? '');
    $email    = trim($data['email'] ?? '');
    $team     = trim($data['teamname'] ?? '');

    if ($team === '') { $skipped++; $errors[] = "Line $line: missing teamname."; continue; }

    // Look up user by idnumber, username, or email
    $user = null;
    if ($idnumber !== '') { $user = $DB->get_record('user', ['idnumber' => $idnumber, 'deleted' => 0]); }
    if (!$user && $username !== '') { $user = $DB->get_record('user', ['username' => $username, 'deleted' => 0]); }
    if (!$user && $email !== '')    { $user = $DB->get_record('user', ['email'    => $email,    'deleted' => 0]); }
    if (!$user) { $skipped++; $errors[] = "Line $line: user not found (idnumber='$idnumber', username='$username', email='$email')."; continue; }

    // Ensure Moodle group exists and add member
    if ($creategroups) {
        $gid = groups_get_group_by_name($course->id, $team);
        if (!$gid) {
            $gid = groups_create_group((object)['courseid' => $course->id, 'name' => $team]);
        }
        if (!groups_is_member($gid, $user->id)) {
            groups_add_member($gid, $user->id);
        }
    }

    // store mapping
    $existing = $DB->get_record('spe_teammap', ['speid' => $spe->id, 'userid' => $user->id]);
    $rec = (object)[
        'speid'       => $spe->id,
        'userid'      => $user->id,
        'teamname'    => $team,
        'rawidnumber' => $idnumber ?: null,
        'rawusername' => $username ?: null,
        'rawemail'    => $email ?: null,
        'timecreated' => time()
    ];
    if ($existing) { $rec->id = $existing->id; $DB->update_record('spe_teammap', $rec); $updated++; }
    else { $DB->insert_record('spe_teammap', $rec); $created++; }
}
fclose($handle);

// Show summary and continue button
echo $OUTPUT->notification("Processed: created $created, updated $updated, skipped $skipped.", 'notifysuccess');
if ($errors) { echo html_writer::tag('pre', implode("\n", $errors)); }
echo $OUTPUT->continue_button(new moodle_url('/mod/spe/view.php', ['id' => $cm->id]));
echo $OUTPUT->footer();
