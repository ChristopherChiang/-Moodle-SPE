<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/course/moodleform_mod.php');

class mod_spe_mod_form extends moodleform_mod {
    public function definition() {
        $mform = $this->_form;

        // Activity name
        $mform->addElement('text', 'name', get_string('pluginname', 'mod_spe'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        // Description.
        $this->standard_intro_elements();

        // Grading/availability sections.
        $this->standard_coursemodule_elements();

        // Buttons.
        $this->add_action_buttons();
    }
}
