<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) 
{
    $settings->add(new admin_setting_configtext(
        'mod_spe/sentiment_url',
        get_string('sentiment_url', 'mod_spe'),
        'Full URL to your FastAPI /analyze endpoint.',
        '', // default empty
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'mod_spe/sentiment_token',
        get_string('sentiment_token', 'mod_spe'),
        'Optional token sent as X-API-TOKEN header.',
        '' // default empty
    ));
}
