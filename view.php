<?php

// -----------------------------------------------------------------------------
// Bootstrap & security
// -----------------------------------------------------------------------------
require('../../config.php');

$cmid   = required_param('id', PARAM_INT);
$cm     = get_coursemodule_from_id('spe', $cmid, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);

// -----------------------------------------------------------------------------
// Page setup
// -----------------------------------------------------------------------------
$PAGE->set_url('/mod/spe/view.php', ['id' => $cm->id]);
$PAGE->set_title('Self and Peer Evaluation');
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('incourse');
$PAGE->add_body_class('spe-compact spe-left');

// Instructor dashboard quick link
if (has_capability('mod/spe:manage', $context)) {
    $PAGE->set_button(
        $OUTPUT->single_button(
            new moodle_url('/mod/spe/instructor.php', ['id' => $cm->id]),
            get_string('instructordashboard', 'spe'),
            'get'
        )
    );
}

// -----------------------------------------------------------------------------
// Double-submission guard (before rendering form)
// -----------------------------------------------------------------------------
$existing = $DB->get_record('spe_submission', [
    'speid'  => $cm->instance,
    'userid' => $USER->id
], '*', IGNORE_MISSING);

if ($existing) {
    $submissionurl = new moodle_url('/mod/spe/submission.php', ['id' => $cm->id]);
    redirect($submissionurl, get_string('alreadysubmitted', 'spe'), 2);
    exit;
}

// -----------------------------------------------------------------------------
// Render header and basic instructions
// -----------------------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->heading('Self and Peer Evaluation');

echo html_writer::tag('div', '
    <p><strong>Please note:</strong> Everything that you put into this form will be kept strictly confidential by the unit coordinator.</p>
    <h4>Using the assessment scales</h4>
    <ul>
        <li>1 = Very poor / obstructive contribution</li>
        <li>2 = Poor contribution</li>
        <li>3 = Acceptable contribution</li>
        <li>4 = Good contribution</li>
        <li>5 = Excellent contribution</li>
    </ul>
', ['class' => 'spe-instructions']);

// -----------------------------------------------------------------------------
// Helpers, constants, and configuration
// -----------------------------------------------------------------------------

/**
 * Count words similarly to the original PHP side:
 * regex: /[\p{L}\p{N}’']+/u
 */
function spe_wordcount(string $text): int {
    if (preg_match_all("/[\\p{L}\\p{N}’']+/u", $text, $m)) {
        return count($m[0]);
    }
    return 0;
}

// Score ranges (used by UI/instructor policy; not enforced server-side except min-words)
const SPE_SCORE_MIN = 5;   // 5 criteria * 1
const SPE_SCORE_MAX = 25;  // 5 criteria * 5

// Criteria shown for scoring (keys used to persist ratings)
$criteria = [
    'effortdocs'    => 'The amount of work and effort put into the Requirements/Analysis Document, the Project Management Plan, and the Design Document.',
    'teamwork'      => 'Willingness to work as part of the group and taking responsibility.',
    'communication' => 'Communication within the group and participation in meetings.',
    'management'    => 'Contribution to management, e.g., work delivered on time.',
    'problemsolve'  => 'Problem solving and creativity for the group’s work.'
];

// Gather peers (same Moodle group as the current user)
$peers = [];
$usergroups = groups_get_user_groups($course->id, $USER->id);
if (!empty($usergroups[0])) {
    $mygroupid = reset($usergroups[0]);
    $members   = groups_get_members($mygroupid, 'u.id, u.firstname, u.lastname, u.username');
    foreach ($members as $u) {
        if ((int)$u->id !== (int)$USER->id) {
            $peers[] = $u;
        }
    }
} else {
    echo $OUTPUT->notification('You are not in any group yet. Please ask your instructor to add you to a group.', 'notifyproblem');
}

// POST/GET flags
$submitted = optional_param('submitted', 0, PARAM_INT);

// Draft autosave preference key
$draftkey  = 'mod_spe_draft_' . $cm->id;
$rawdraft  = (string) get_user_preferences($draftkey, '', $USER);
$draftdata = $rawdraft ? json_decode($rawdraft, true) : null;

// Prefill defaults
$prefill = [
    'selfdesc'   => '',
    'reflection' => '',
    'selfscores' => [],
    'peerscores' => [],
    'peertexts'  => []
];

// Merge draft into prefill (GET only; if POST, we use the POST values)
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $draftdata) {
    foreach (['selfdesc','reflection'] as $k) {
        if (!empty($draftdata[$k]) && is_string($draftdata[$k])) {
            $prefill[$k] = $draftdata[$k];
        }
    }
    if (!empty($draftdata['selfscores']) && is_array($draftdata['selfscores'])) {
        $prefill['selfscores'] = array_merge($prefill['selfscores'], $draftdata['selfscores']);
    }
    if (!empty($draftdata['peerscores']) && is_array($draftdata['peerscores'])) {
        $prefill['peerscores'] = array_merge($prefill['peerscores'], $draftdata['peerscores']);
    }
    if (!empty($draftdata['peertexts']) && is_array($draftdata['peertexts'])) {
        $prefill['peertexts']  = array_merge($prefill['peertexts'], $draftdata['peertexts']);
    }
}

// -----------------------------------------------------------------------------
// Handle submission
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {

    // 1) Collect inputs
    $selfdesc   = trim(optional_param('selfdesc', '', PARAM_RAW));
    $reflection = trim(optional_param('reflection', '', PARAM_RAW));

    $selfscores = [];
    foreach ($criteria as $key => $label) {
        $selfscores[$key] = optional_param("self_{$key}", 0, PARAM_INT);
    }

    $peerscores = [];
    $peertexts  = [];
    foreach ($peers as $p) {
        $peertexts[$p->id] = trim(optional_param("comment_{$p->id}", '', PARAM_RAW_TRIMMED));
        foreach ($criteria as $key => $label) {
            $peerscores[$p->id][$key] = optional_param("peer_{$p->id}_{$key}", 0, PARAM_INT);
        }
    }

    // Keep for re-render on validation errors
    $prefill = [
        'selfdesc'   => $selfdesc,
        'reflection' => $reflection,
        'selfscores' => $selfscores,
        'peerscores' => $peerscores,
        'peertexts'  => $peertexts
    ];

    // 2) Validate
    $errors   = [];
    $refwords = spe_wordcount($reflection);
    if ($refwords < 100) {
        $errors[] = "Reflection must be at least 100 words (currently $refwords).";
    }

    if (!empty($errors)) {
        foreach ($errors as $e) {
            echo $OUTPUT->notification($e, 'notifyproblem');
        }
        $submitted = 0;

    } else {

        // 3) Guard against double insert (race)
        if ($DB->record_exists('spe_submission', ['speid' => $cm->instance, 'userid' => $USER->id])) {
            $submissionurl = new moodle_url('/mod/spe/submission.php', ['id' => $cm->id]);
            redirect($submissionurl, get_string('alreadysubmitted', 'mod_spe'), 2);
            exit;
        }

        // 4) Insert the core submission
        $DB->insert_record('spe_submission', (object)[
            'speid'       => $cm->instance,
            'userid'      => $USER->id,
            'selfdesc'    => $selfdesc,
            'reflection'  => $reflection,
            'wordcount'   => $refwords,
            'timecreated' => time(),
            'timemodified'=> time()
        ]);

        // 5) Reset and insert ratings
        $DB->delete_records('spe_rating', ['speid' => $cm->instance, 'raterid' => $USER->id]);

        // 5a) Self scores (criterion rows for self)
        foreach ($criteria as $key => $label) {
            $score = $selfscores[$key] ?? 0;
            if ($score >= 1 && $score <= 5) {
                $DB->insert_record('spe_rating', (object)[
                    'speid'       => $cm->instance,
                    'raterid'     => $USER->id,
                    'rateeid'     => $USER->id,
                    'criterion'   => $key,
                    'score'       => $score,
                    'comment'     => $selfdesc ?: null,
                    'timecreated' => time()
                ]);
            }
        }

        // 5b) Peer scores (criterion rows per teammate)
        foreach ($peers as $p) {
            $peercomment = $peertexts[$p->id] ?? '';
            foreach ($criteria as $key => $label) {
                $score = $peerscores[$p->id][$key] ?? 0;
                if ($score >= 1 && $score <= 5) {
                    $DB->insert_record('spe_rating', (object)[
                        'speid'       => $cm->instance,
                        'raterid'     => $USER->id,
                        'rateeid'     => $p->id,
                        'criterion'   => $key,
                        'score'       => $score,
                        'comment'     => $peercomment ?: null,
                        'timecreated' => time()
                    ]);
                }
            }
        }

        // 6) Queue text for sentiment analysis (server-side only; status='pending')
        if ($DB->get_manager()->table_exists('spe_sentiment')) {

            // Queue each peer comment
            foreach ($peers as $p) {
                $peercomment = $peertexts[$p->id] ?? '';
                if ($peercomment !== '') {
                    $DB->insert_record('spe_sentiment', (object)[
                        'speid'       => $cm->instance,
                        'raterid'     => $USER->id,    // who wrote it
                        'rateeid'     => $p->id,       // who it's about
                        'type'        => 'peer_comment',
                        'text'        => $peercomment,
                        'status'      => 'pending',     // pending until instructor/cron processes
                        'timecreated' => time()
                    ]);
                }
            }

            // Queue the reflection (replace any existing pending one from this rater)
            if ($reflection !== '') {
                $DB->delete_records('spe_sentiment', [
                    'speid'   => $cm->instance,
                    'raterid' => $USER->id,
                    'rateeid' => $USER->id,
                    'type'    => 'reflection',
                    'status'  => 'pending'
                ]);
                $DB->insert_record('spe_sentiment', (object)[
                    'speid'       => $cm->instance,
                    'raterid'     => $USER->id,
                    'rateeid'     => $USER->id,
                    'type'        => 'reflection',
                    'text'        => $reflection,
                    'status'      => 'pending',
                    'timecreated' => time()
                ]);
            }
        }

        // 8) Clean up draft and redirect to the submission page
        unset_user_preference($draftkey, $USER);
        $submissionurl = new moodle_url('/mod/spe/submission.php', ['id' => $cm->id]);
        redirect($submissionurl, 'Your submission has been saved successfully!', 2);
        exit;
    }
}

// -----------------------------------------------------------------------------
// Render the form (GET or POST with validation errors)
// -----------------------------------------------------------------------------
if (!$submitted) {

    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => new moodle_url('/mod/spe/view.php', ['id' => $cm->id]),
    ]);

    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'submitted', 'value' => 1]);

    // --- Self Evaluation (scores) ---
    echo html_writer::tag('h3', 'Self Evaluation');
    $sel = $prefill['selfscores'] ?? [];
    foreach ($criteria as $key => $label) {
        echo html_writer::tag('p', $label);
        echo '<select name="self_' . $key . '" required>';
        echo '<option value="">--</option>';
        for ($i = 1; $i <= 5; $i++) {
            $selected = (isset($sel[$key]) && (int)$sel[$key] === $i) ? ' selected' : '';
            echo "<option value=\"$i\"$selected>$i</option>";
        }
        echo '</select><br>';
    }

    // --- Self description (text) ---
    echo html_writer::tag('h4', 'Briefly describe how you believe you contributed to the project process:');
    echo html_writer::tag('textarea', $prefill['selfdesc'] ?? '', [
        'name'  => 'selfdesc',
        'rows'  => 4,
        'cols'  => 80,
        'class' => 'spe-textarea'
    ]);

    // --- Reflection (text) ---
    echo html_writer::tag('h4', 'Reflection (minimum 100 words)');
    echo html_writer::tag('textarea', $prefill['reflection'] ?? '', [
        'name'  => 'reflection',
        'rows'  => 6,
        'cols'  => 80,
        'class' => 'spe-textarea'
    ]);

    // --- Peer Evaluation (scores + comment per teammate) ---
    if (!empty($peers)) {
        echo html_writer::tag('h3', 'Evaluation of Team Members');

        $psel  = $prefill['peerscores'] ?? [];
        $ptext = $prefill['peertexts']  ?? [];

        foreach ($peers as $p) {
            echo html_writer::tag('h4', 'Member: ' . fullname($p) . " ({$p->username})");

            foreach ($criteria as $key => $label) {
                echo html_writer::tag('p', $label);
                echo '<select name="peer_' . $p->id . '_' . $key . '" required>';
                echo '<option value="">--</option>';
                for ($i = 1; $i <= 5; $i++) {
                    $selected = (isset($psel[$p->id][$key]) && (int)$psel[$p->id][$key] === $i) ? ' selected' : '';
                    echo "<option value=\"$i\"$selected>$i</option>";
                }
                echo '</select><br>';
            }

            echo html_writer::tag('p', 'Briefly describe how you believe this person contributed to the project process:');
            echo html_writer::tag('textarea', $ptext[$p->id] ?? '', [
                'name'  => "comment_{$p->id}",
                'rows'  => 4,
                'cols'  => 80,
                'class' => 'spe-textarea'
            ]);

            echo html_writer::empty_tag('hr');
        }
    } else {
        echo $OUTPUT->notification('No peers found in your group. You can still submit your self-evaluation.', 'notifywarning');
    }

    // Submit
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Submit']);
    echo html_writer::end_tag('form');

    // -------------------------------------------------------------------------
    // Minimal styles + scripts
    // -------------------------------------------------------------------------
    // Offline word-count HUD (under each textarea). No API, no network.
    echo '<style>
        .spe-textarea { display:block; width: 100%; max-width: 900px; }
        .spe-wc-hud   { margin-top: 6px; font-size: 12px; color: #6b7280; }
    </style>';

    ?>
    <script>
    (function () {
        // ---------- AUTOSAVE DRAFT (unchanged logic, local endpoint draft.php) ----------
        const form = document.querySelector('form[action*="/mod/spe/view.php"]');
        if (!form) return;

        const cmid       = <?php echo (int)$cm->id; ?>;
        const sesskeyVal = "<?php echo sesskey(); ?>";
        const draftUrl   = M.cfg.wwwroot + "/mod/spe/draft.php?id=" + cmid + "&sesskey=" + encodeURIComponent(sesskeyVal);

        function readFormJSON() {
            const data = {
                selfdesc:   form.querySelector('[name="selfdesc"]')?.value || '',
                reflection: form.querySelector('[name="reflection"]')?.value || '',
                selfscores: {},
                peerscores: {},
                peertexts:  {}
            };

            form.querySelectorAll('select[name^="self_"]').forEach(sel => {
                const key = sel.name.replace(/^self_/, '');
                data.selfscores[key] = sel.value ? parseInt(sel.value, 10) : 0;
            });

            form.querySelectorAll('select[name^="peer_"]').forEach(sel => {
                const parts = sel.name.split('_');
                if (parts.length >= 3) {
                    const pid = parts[1];
                    const key = parts.slice(2).join('_');
                    if (!data.peerscores[pid]) data.peerscores[pid] = {};
                    data.peerscores[pid][key] = sel.value ? parseInt(sel.value, 10) : 0;
                }
            });

            form.querySelectorAll('textarea[name^="comment_"]').forEach(t => {
                const pid = t.name.replace(/^comment_/, '');
                data.peertexts[pid] = t.value || '';
            });

            const json = JSON.stringify(data);
            if (json.length > 180000) {
                try {
                    const d = JSON.parse(json);
                    d.reflection = (d.reflection || '').slice(0, 30000);
                    for (const k in d.peertexts) d.peertexts[k] = (d.peertexts[k] || '').slice(0, 15000);
                    return JSON.stringify(d);
                } catch(e) { return JSON.stringify({}); }
            }
            return json;
        }

        let saveTimer = null, lastSent = '';
        function queueSave() {
            window.clearTimeout(saveTimer);
            saveTimer = window.setTimeout(async () => {
                const body = readFormJSON();
                if (body === lastSent) return;
                lastSent = body;
                try {
                    await fetch(draftUrl + "&action=save", {
                        method: "POST",
                        headers: {"Content-Type":"application/json"},
                        body
                    });
                } catch(e) { /* ignore autosave errors */ }
            }, 800);
        }

        form.addEventListener('input', queueSave);
        form.addEventListener('change', queueSave);

        (async function restoreDraft() {
            try {
                const res = await fetch(draftUrl + "&action=load");
                const data = await res.json();
                if (!data || data.exists === false) return;

                if (data.selfdesc && !form.selfdesc?.value) form.selfdesc.value = data.selfdesc;
                if (data.reflection && !form.reflection?.value) form.reflection.value = data.reflection;

                if (data.selfscores) {
                    Object.keys(data.selfscores).forEach(k => {
                        const el = form.querySelector(`[name="self_${k}"]`);
                        if (el && !el.value) el.value = data.selfscores[k] || '';
                    });
                }
                if (data.peerscores) {
                    Object.keys(data.peerscores).forEach(pid => {
                        const obj = data.peerscores[pid];
                        Object.keys(obj || {}).forEach(k => {
                            const el = form.querySelector(`[name="peer_${pid}_${k}"]`);
                            if (el && !el.value) el.value = obj[k] || '';
                        });
                    });
                }
                if (data.peertexts) {
                    Object.keys(data.peertexts).forEach(pid => {
                        const el = form.querySelector(`[name="comment_${pid}"]`);
                        if (el && !el.value) el.value = data.peertexts[pid] || '';
                    });
                }
            } catch(e) {}
        })();

        // ---------- OFFLINE WORD COUNT HUD (no API) ----------
        function wordCount(text) {
            try {
                const m = text.match(/[\p{L}\p{N}’']+/gu);
                return m ? m.length : 0;
            } catch (e) {
                // Fallback if Unicode property escapes unsupported
                const m = (text || '').trim().split(/\s+/).filter(Boolean);
                return m.length;
            }
        }

        function attachHud(textarea) {
            if (!textarea || textarea._wcHudAttached) return;
            textarea._wcHudAttached = true;

            const hud = document.createElement('div');
            hud.className = 'spe-wc-hud';
            hud.textContent = 'Words: 0';
            textarea.insertAdjacentElement('afterend', hud);

            const update = () => { hud.textContent = 'Words: ' + wordCount(textarea.value || ''); };
            textarea.addEventListener('input', update);
            textarea.addEventListener('change', update);
            update();
        }

        // Apply to selfdesc, reflection, and all peer comment textareas
        attachHud(document.querySelector('textarea[name="selfdesc"]'));
        attachHud(document.querySelector('textarea[name="reflection"]'));
        document.querySelectorAll('textarea[name^="comment_"]').forEach(attachHud);

    })();
    </script>
    <?php
}

// -----------------------------------------------------------------------------
// Footer
// -----------------------------------------------------------------------------
echo $OUTPUT->footer();
