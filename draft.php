<?php

require('../../config.php');

$cmid = required_param('id', PARAM_INT);
$action = required_param('action', PARAM_ALPHA); 

$cm = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_course_login($course, true, $cm);
require_sesskey();

$context = context_module::instance($cm->id);
require_capability('mod/spe:submit', $context); // students

$key = 'mod_spe_draft_' . $cm->id;

switch ($action) 
{
    case 'save':
        require_capability('mod/spe:submit', $context);
        $raw = file_get_contents('php://input');
        if (core_text::strlen($raw) > 200000) 
        { 
            $raw = substr($raw, 0, 200000);
        }
        set_user_preference($key, $raw, $USER);
        echo json_encode(['ok' => true]);
        break;

    case 'load':
        $json = (string) get_user_preferences($key, '', $USER);
        // Return empty string if not found.
        header('Content-Type: application/json');
        echo $json !== '' ? $json : json_encode(['exists'=>false]);
        break;

    case 'clear':
        unset_user_preference($key, $USER);
        echo json_encode(['ok' => true]);
        break;

    default:
        throw new moodle_exception('invalidaction');
}
