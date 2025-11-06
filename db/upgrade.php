<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_spe_upgrade(int $oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2025100600) {

        // Spe
        $table = new xmldb_table('spe');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',           XMLDB_TYPE_INTEGER,  '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('course',       XMLDB_TYPE_INTEGER,  '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('name',         XMLDB_TYPE_CHAR,    '255', null, XMLDB_NOTNULL, null, null);
            $table->add_field('intro',        XMLDB_TYPE_TEXT,       null, null, null, null, null);
            $table->add_field('introformat',  XMLDB_TYPE_INTEGER,   '2', null, XMLDB_NOTNULL, null, '1');
            $table->add_field('timecreated',  XMLDB_TYPE_INTEGER,  '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER,  '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary',  XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('coursefk', XMLDB_KEY_FOREIGN, ['course'], 'course', ['id']);

            $dbman->create_table($table);
        }

        // Spe_submission
        $table = new xmldb_table('spe_submission');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',           XMLDB_TYPE_INTEGER,  '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('speid',        XMLDB_TYPE_INTEGER,  '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid',       XMLDB_TYPE_INTEGER,  '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('selfdesc',     XMLDB_TYPE_TEXT,       null, null, null, null, null);
            $table->add_field('reflection',   XMLDB_TYPE_TEXT,       null, null, null, null, null);
            $table->add_field('wordcount',    XMLDB_TYPE_INTEGER,  '10', null, null, null, null);
            $table->add_field('timecreated',  XMLDB_TYPE_INTEGER,  '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER,  '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('spefk',   XMLDB_KEY_FOREIGN, ['speid'],  'spe',  ['id']);
            $table->add_key('userfk',  XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

            $table->add_index('unique_by_student', XMLDB_INDEX_UNIQUE, ['speid','userid']);

            $dbman->create_table($table);
        }

        // Spe_rating
        $table = new xmldb_table('spe_rating');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',          XMLDB_TYPE_INTEGER,  '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('speid',       XMLDB_TYPE_INTEGER,  '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('raterid',     XMLDB_TYPE_INTEGER,  '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('rateeid',     XMLDB_TYPE_INTEGER,  '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('criterion',   XMLDB_TYPE_CHAR,      '50', null, XMLDB_NOTNULL, null, null);
            $table->add_field('score',       XMLDB_TYPE_INTEGER,  '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('comment',     XMLDB_TYPE_TEXT,       null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER,  '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('spefk',   XMLDB_KEY_FOREIGN, ['speid'],   'spe',  ['id']);
            $table->add_key('raterfk', XMLDB_KEY_FOREIGN, ['raterid'], 'user', ['id']);
            $table->add_key('rateefk', XMLDB_KEY_FOREIGN, ['rateeid'], 'user', ['id']);

            $table->add_index('by_pair', XMLDB_INDEX_NOTUNIQUE, ['speid','raterid','rateeid','criterion']);

            $dbman->create_table($table);
        }

        // Spe teammap
        $table = new xmldb_table('spe_teammap');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',          XMLDB_TYPE_INTEGER,  '10',  null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('speid',       XMLDB_TYPE_INTEGER,  '10',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('userid',      XMLDB_TYPE_INTEGER,  '10',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('teamname',    XMLDB_TYPE_CHAR,     '100', null, XMLDB_NOTNULL, null, null);
            $table->add_field('rawidnumber', XMLDB_TYPE_CHAR,     '100', null, null, null, null);
            $table->add_field('rawusername', XMLDB_TYPE_CHAR,     '100', null, null, null, null);
            $table->add_field('rawemail',    XMLDB_TYPE_CHAR,     '255', null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER,  '10',  null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('spefk',   XMLDB_KEY_FOREIGN, ['speid'],  'spe',  ['id']);
            $table->add_key('userfk',  XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

            $table->add_index('spe_user_unique', XMLDB_INDEX_UNIQUE, ['speid','userid']);

            $dbman->create_table($table);
        }

        // Spe sentiment
        $table = new xmldb_table('spe_sentiment');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',           XMLDB_TYPE_INTEGER,  '10',   null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('speid',        XMLDB_TYPE_INTEGER,  '10',   null, XMLDB_NOTNULL, null, null);
            $table->add_field('raterid',      XMLDB_TYPE_INTEGER,  '10',   null, XMLDB_NOTNULL, null, null);
            $table->add_field('rateeid',      XMLDB_TYPE_INTEGER,  '10',   null, XMLDB_NOTNULL, null, null);
            $table->add_field('type',         XMLDB_TYPE_CHAR,      '20',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('text',         XMLDB_TYPE_TEXT,        null, null, null,        null, null);
            $table->add_field('sentiment',    XMLDB_TYPE_NUMBER,  '10,4',  null, null,        null, null);
            $table->add_field('label',        XMLDB_TYPE_CHAR,      '20',  null, null,        null, null);
            $table->add_field('status',       XMLDB_TYPE_CHAR,      '20',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('timecreated',  XMLDB_TYPE_INTEGER,   '10',  null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER,   '10',  null, null,         null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

            $dbman->create_table($table);
        }


        $table = new xmldb_table('spe_disparity');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id',          XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('speid',       XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0');
            $table->add_field('raterid',     XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0');
            $table->add_field('rateeid',     XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0');
            $table->add_field('label',       XMLDB_TYPE_CHAR,    '10', null, null,        null, '');
            $table->add_field('scoretotal',  XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, null, null, '0');
            $table->add_field('commenttext', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('isdisparity', XMLDB_TYPE_INTEGER, '1', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, '0');

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('spe_rater_ratee_ix', XMLDB_INDEX_UNIQUE, ['speid','raterid','rateeid']);

            $dbman->create_table($table);
        }


        upgrade_mod_savepoint(true, 2025100600, 'spe');
    }


    return true;
}
