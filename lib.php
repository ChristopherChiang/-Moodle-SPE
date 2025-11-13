<?php
defined('MOODLE_INTERNAL') || die();

// Declare module supports
function spe_supports($feature) 
{
    switch ($feature) 
    {
        case FEATURE_MOD_INTRO: return true;
        default: return null;
    }
}

// Create instance
function spe_add_instance($data, $mform = null) 
{
    global $DB;
    $data->timecreated  = time();
    $data->timemodified = time();
    return $DB->insert_record('spe', $data);
}

// update Instance
function spe_update_instance($data, $mform = null) 
{
    global $DB;
    $data->id = $data->instance;      
    $data->timemodified = time();
    return $DB->update_record('spe', $data);
}

// Delete Instance
function spe_delete_instance($id) 
{
    global $DB;
    if (!$DB->record_exists('spe', ['id' => $id])) return false;
    $DB->delete_records('spe', ['id' => $id]);
    return true;
}

// Report
function spe_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $node) 
{
    global $PAGE;

    if (empty($PAGE->cm)) 
    {
        return;
    }

    $cmid    = $PAGE->cm->id;
    $context = context_module::instance($cmid);

    // Instructor dashboard
    if (has_capability('mod/spe:manage', $context)) 
    {
        $dashurl = new moodle_url('/mod/spe/instructor.php', ['id' => $cmid]);
        $node->add(
            get_string('instructordashboard', 'spe'),
            $dashurl,
            navigation_node::TYPE_SETTING,
            null,
            'spe_instructor_dashboard',
            new pix_icon('i/settings', ''));
    }

    // Report link
    if (has_capability('mod/spe:viewreports', $context)) 
    {
        $repurl = new moodle_url('/mod/spe/analysis_report.php', ['id' => $cmid]);
        $node->add(
            get_string('analysisreport', 'spe'),
            $repurl,
            navigation_node::TYPE_SETTING,
            null,
            'spe_analysis_report',
            new pix_icon('i/report', ''));
    }

}


