<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

use IndianOpinion\AudioToPost\Adapter\HttpMultipartAdapter;
use IndianOpinion\AudioToPost\AudioToPostException;
use IndianOpinion\AudioToPost\Draft\Prompts;
use IndianOpinion\AudioToPost\Plugin;
use IndianOpinion\AudioToPost\Run;
use IndianOpinion\AudioToPost\RunState;
use IndianOpinion\AudioToPost\Source;
use IndianOpinion\AudioToPost\Transcript\Exporter;
use IndianOpinion\AudioToPost\Transcript\Timecode;
use IndianOpinion\AudioToPost\Transcript\Turns;

/**
 * Admin screens: runs, one run (estimate and confirmation), transcript
 * editing, settings and status. Every form posts to admin-post.php with
 * a nonce and a capability check. Output is escaped.
 */
final class Admin
{
    public const SLUG = 'audio-to-post';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_notices', [self::class, 'notices']);
        add_action('add_meta_boxes_post', [self::class, 'metaBox']);
        foreach (['new_run', 'confirm', 'cancel', 'retry', 'reestimate', 'draft', 'edit', 'export', 'settings', 'switch', 'delete'] as $a) {
            $handler = [self::class, 'handle' . str_replace('_', '', ucwords($a, '_'))];
            if (is_callable($handler)) {
                add_action('admin_post_audiopost_' . $a, $handler);
            }
        }
    }

    public static function menu(): void
    {
        add_menu_page('Audio to post', 'Audio to post', Access::createCap(), self::SLUG, [self::class, 'pageRuns'], 'dashicons-microphone', 26);
        add_submenu_page(self::SLUG, 'Runs', 'Runs', Access::createCap(), self::SLUG, [self::class, 'pageRuns']);
        add_submenu_page(self::SLUG, 'Settings', 'Settings', Access::manageCap(), self::SLUG . '-settings', [self::class, 'pageSettings']);
        add_submenu_page(self::SLUG, 'Status', 'Status', Access::manageCap(), self::SLUG . '-status', [self::class, 'pageStatus']);
    }

    /** @param array<string, mixed> $args */
    private static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php'));
    }

    private static function form(string $action, string $inner, string $button, string $class = 'button'): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="audiopost_' . esc_attr($action) . '">'
            . wp_nonce_field('audiopost_' . $action, '_audiopost_nonce', true, false)
            . $inner
            . '<button type="submit" class="' . esc_attr($class) . '">' . esc_html($button) . '</button></form> ';
    }

    private static function hidden(string $name, string|int $value): string
    {
        return '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '">';
    }

    private static function verify(string $action, bool $manage = false): void
    {
        check_admin_referer('audiopost_' . $action, '_audiopost_nonce');
        if (!($manage ? Access::canManage() : Access::canCreate())) {
            wp_die(esc_html__('You are not allowed to do this.', 'audio-to-post'), 403);
        }
    }

    private static function postedRun(): Run
    {
        $run = Plugin::instance()->repo()->get(absint($_POST['run'] ?? $_GET['run'] ?? 0));
        if (!Access::canAct($run) || $run === null) {
            wp_die(esc_html__('Run not found.', 'audio-to-post'), 404);
        }

        return $run;
    }

    /** @return never */
    private static function back(int $runId, string $msg = '', string $type = 'success'): void
    {
        $args = $runId > 0 ? ['run' => $runId] : [];
        if ($msg !== '') {
            $args['ap_msg'] = rawurlencode($msg);
            $args['ap_type'] = $type;
        }
        wp_safe_redirect(self::url($args));
        exit;
    }

    // Screens.

    public static function pageRuns(): void
    {
        if (!Access::canCreate()) {
            wp_die(esc_html__('You are not allowed to see this page.', 'audio-to-post'), 403);
        }
        echo '<div class="wrap">';
        if (isset($_GET['ap_msg'])) {
            $type = ($_GET['ap_type'] ?? '') === 'error' ? 'notice-error' : 'notice-success';
            echo '<div class="notice ' . esc_attr($type) . '"><p>' . esc_html(sanitize_text_field(rawurldecode((string) wp_unslash($_GET['ap_msg'])))) . '</p></div>';
        }
        $runId = absint($_GET['run'] ?? 0);
        if ($runId > 0) {
            $run = Plugin::instance()->repo()->get($runId);
            if (!Access::canAct($run) || $run === null) {
                echo '<p>' . esc_html__('Run not found.', 'audio-to-post') . '</p></div>';

                return;
            }
            isset($_GET['transcript']) ? self::screenTranscript($run) : self::screenRun($run);
            echo '</div>';

            return;
        }
        if (isset($_GET['feed'])) {
            self::screenFeed(esc_url_raw((string) wp_unslash($_GET['feed'])));
            echo '</div>';

            return;
        }
        echo '<h1>Audio to post</h1>';
        self::newRunForm();
        $repo = Plugin::instance()->repo();
        $ids = $repo->find([], 50, Access::canManage() ? null : get_current_user_id(), 'DESC');
        echo '<h2>Runs</h2><table class="widefat striped"><thead><tr><th>Run</th><th>Source</th><th>State</th><th>Progress</th><th>Created</th></tr></thead><tbody>';
        foreach ($ids as $id) {
            $r = $repo->get($id);
            if ($r === null) {
                continue;
            }
            echo '<tr><td><a href="' . esc_url(self::url(['run' => $r->id])) . '">' . (int) $r->id . '</a></td><td>' . esc_html($r->title()) . '</td><td>' . esc_html($r->state . ($r->reason !== '' ? ' (' . $r->reason . ')' : '')) . '</td><td>' . (int) $r->chunksDone . '/' . (int) $r->chunksTotal . '</td><td>' . esc_html((string) wp_date('Y-m-d H:i', $r->createdAt)) . '</td></tr>';
        }
        if ($ids === []) {
            echo '<tr><td colspan="5">No runs yet.</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function modeSelect(string $selected): string
    {
        $available = Plugin::instance()->drafts()->callerAvailable();
        $out = '<select name="mode">';
        foreach (['article' => 'Article', 'show_notes' => 'Show notes', 'transcript' => 'Cleaned transcript', 'none' => 'Transcript only, no draft'] as $k => $label) {
            $disabled = !$available && in_array($k, ['article', 'show_notes'], true);
            $out .= '<option value="' . esc_attr($k) . '"' . selected($selected, $k, false) . disabled($disabled, true, false) . '>' . esc_html($label . ($disabled ? ' (unavailable: no_llm_caller)' : '')) . '</option>';
        }

        return $out . '</select>';
    }

    private static function newRunForm(): void
    {
        $s = Plugin::instance()->settings();
        $inner = '<table class="form-table"><tr><th>Source</th><td>'
            . '<p><label><input type="radio" name="kind" value="upload" checked> Upload audio </label><input type="file" name="audio" accept="audio/*"></p>'
            . '<p><label><input type="radio" name="kind" value="attachment"> Media Library attachment ID </label><input type="number" name="attachment_id" min="1"></p>'
            . '<p><label><input type="radio" name="kind" value="url"> Audio file URL </label><input type="url" name="url" class="regular-text"></p>'
            . '<p><label><input type="radio" name="kind" value="feed"> Podcast feed URL </label><input type="url" name="feed_url" class="regular-text"> (you pick the episode next)</p>'
            . '<p><label><input type="radio" name="kind" value="transcript"> Transcript file (WebVTT, SRT or JSON) </label><input type="file" name="transcript_file" accept=".vtt,.srt,.json"></p>'
            . '</td></tr><tr><th>Draft</th><td>' . self::modeSelect($s->string('default_mode')) . '</td></tr>'
            . '<tr><th>Language</th><td><input type="text" name="language" value="' . esc_attr($s->string('language')) . '" class="small-text"> <span class="description">auto or a tag such as en, hi, ta-IN</span></td></tr>'
            . '<tr><th>Rights</th><td><label><input type="checkbox" name="rights_confirmed" value="1"> I have the right to transcribe and publish from this recording</label></td></tr></table>';
        echo '<h2>New run</h2><form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="audiopost_new_run">' . wp_nonce_field('audiopost_new_run', '_audiopost_nonce', true, false)
            . $inner . '<p><button class="button button-primary" type="submit">Measure and estimate</button> <span class="description">Nothing is paid for until you confirm the estimate.</span></p></form>';
    }

    private static function screenFeed(string $feed): void
    {
        echo '<h1>Pick an episode</h1>';
        try {
            $episodes = Plugin::instance()->runs()->feedEpisodes($feed);
        } catch (AudioToPostException $e) {
            echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';

            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Title</th><th>Published</th><th>Duration</th><th>Size</th><th></th></tr></thead><tbody>';
        foreach ($episodes as $ep) {
            $common = self::hidden('kind', 'feed_episode') . self::hidden('feed_url', $feed) . self::hidden('guid', $ep['guid']) . self::hidden('episode_title', $ep['title'])
                . self::hidden('published', $ep['published']) . self::hidden('enclosure_url', $ep['enclosure_url']) . self::hidden('mode', Plugin::instance()->settings()->string('default_mode'));
            $rights = '<label><input type="checkbox" name="rights_confirmed" value="1"> rights confirmed</label> ';
            $actions = self::form('new_run', $common . $rights, 'Use the audio', 'button button-primary');
            if ($ep['transcript_url'] !== null) {
                $actions .= self::form('new_run', $common . self::hidden('transcript_url', $ep['transcript_url']) . self::hidden('transcript_type', (string) $ep['transcript_type']) . self::hidden('use_transcript', 1), 'Use the published transcript (free)');
            }
            echo '<tr><td>' . esc_html($ep['title']) . '</td><td>' . esc_html($ep['published']) . '</td><td>' . esc_html($ep['duration'] === null ? '' : Timecode::hms($ep['duration'] * 1000)) . '</td><td>' . esc_html($ep['enclosure_size'] === null ? '' : (string) size_format($ep['enclosure_size'])) . '</td><td>' . $actions . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function screenRun(Run $run): void
    {
        $p = Plugin::instance();
        $repo = $p->repo();
        echo '<h1>Run ' . (int) $run->id . ': ' . esc_html($run->title()) . '</h1>';
        echo '<p><strong>State:</strong> ' . esc_html($run->state . ($run->reason !== '' ? ' (' . $run->reason . ')' : '')) . ' &middot; <strong>Chunks:</strong> ' . (int) $run->chunksDone . '/' . (int) $run->chunksTotal . '</p>';
        if ($run->errorCode !== null && $run->errorCode !== '') {
            echo '<div class="notice notice-error inline"><p>' . esc_html($run->errorCode . ': ' . (string) $run->errorMessage) . '</p></div>';
            if ($run->errorCode === 'feed_url' && $run->source->get('url') !== null) {
                echo '<p><a class="button" href="' . esc_url(self::url(['feed' => (string) $run->source->get('url')])) . '">Open the feed picker</a></p>';
            }
        }
        $e = $run->estimate;
        if ($e !== null) {
            $a = $run->audio;
            echo '<h2>Estimate</h2><table class="widefat" style="max-width:720px"><tbody>';
            $rows = [
                'Source' => $run->source->label(),
                'Duration' => $a === null ? 'n/a' : ($a->durationMs === null ? 'unknown duration, upper bound from file size' : Timecode::hms($a->durationMs) . ($a->durationExact ? ' (exact)' : ' (estimated)')),
                'Chunks' => (string) $e->chunks,
                'Billed audio minutes' => number_format($e->billedMinutes(), 1) . ($e->upperBound ? ' (upper bound)' : ''),
                'Draft mode' => $run->mode(),
                'Draft tokens' => sprintf('about %d in, up to %d out', $e->tokensIn, $e->tokensOut),
                'Price' => $e->priceLine(),
            ];
            foreach ($p->guard()->today() as $unit => $row) {
                $rows['Today: ' . $unit] = 'used ' . $row['used'] . ($row['cap'] === null ? ', no cap' : ', cap ' . $row['cap'] . ', left ' . $row['left']);
            }
            foreach ($rows as $k => $v) {
                echo '<tr><th>' . esc_html($k) . '</th><td>' . esc_html((string) $v) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<p>';
        $h = self::hidden('run', $run->id);
        if ($run->state === RunState::AWAITING_CONFIRMATION && $e !== null) {
            $dup = (int) $repo->flag($run->id, 'duplicate_of');
            if ($dup > 0) {
                echo self::form('confirm', $h . self::hidden('fingerprint', (string) $p->runs()->estimate($run->id, false, true)->fingerprint) . self::hidden('reuse', 1), 'Reuse the transcript of run ' . $dup . ' (free)', 'button button-primary');
                $p->runs()->estimate($run->id);
                $e = $repo->get($run->id)->estimate ?? $e;
            }
            $fit = $p->guard()->checkEstimate($e);
            if ($fit !== null && $fit['whole']) {
                echo '<span class="description">This run needs more than the whole daily ' . esc_html($fit['unit']) . ' cap. Raise the cap or shorten the recording.</span> ';
            } else {
                $label = $fit === null ? 'Start' : 'Queue for tomorrow (' . $fit['unit'] . ' cap reached today)';
                echo self::form('confirm', $h . self::hidden('fingerprint', $e->fingerprint), $dup > 0 ? 'Transcribe again' : $label, $dup > 0 ? 'button' : 'button button-primary');
            }
        }
        if (RunState::canMove($run->state, RunState::CANCELLED)) {
            echo self::form('cancel', $h, 'Cancel');
        }
        if ($run->state === RunState::FAILED) {
            echo self::form('retry', $h, 'Retry');
        }
        if ($run->state === RunState::EXPIRED) {
            echo self::form('reestimate', $h, 'Estimate again');
        }
        if (in_array($run->state, RunState::FINAL, true)) {
            echo self::form('delete', $h, 'Delete run (drafts are kept)', 'button button-link-delete');
        }
        echo '</p>';
        if ($repo->transcript($run->id) !== null) {
            echo '<h2>Transcript</h2><p><a class="button" href="' . esc_url(self::url(['run' => $run->id, 'transcript' => 1])) . '">View and edit</a> ';
            foreach (Exporter::FORMATS as $f) {
                echo '<a class="button" href="' . esc_url(wp_nonce_url(add_query_arg(['action' => 'audiopost_export', 'run' => $run->id, 'format' => $f], admin_url('admin-post.php')), 'audiopost_export', '_audiopost_nonce')) . '">' . esc_html(strtoupper($f)) . '</a> ';
            }
            echo '</p>';
            if (in_array($run->state, [RunState::TRANSCRIPT_READY, RunState::DRAFT_READY], true)) {
                $tokens = $p->drafts()->estimateFor($run->id, 'article');
                echo self::form('draft', $h . self::modeSelect('transcript'), 'Create another draft') . '<span class="description">A model draft uses about ' . (int) $tokens . ' tokens.</span>';
            }
        }
        if ($run->draftIds !== []) {
            echo '<h2>Drafts</h2><ul>';
            foreach ($run->draftIds as $pid) {
                echo '<li><a href="' . esc_url((string) get_edit_post_link($pid)) . '">' . esc_html(get_the_title($pid) ?: '#' . $pid) . '</a></li>';
            }
            echo '</ul>';
        }
        self::reviewNotes($repo->meta($run->id, 'review_notes', []));
        $usage = $repo->usage($run->id);
        if ($usage !== [] && $e !== null) {
            $sec = 0;
            $tin = 0;
            $tout = 0;
            foreach ($usage as $u) {
                $sec += (int) ($u['billed_seconds'] ?? 0);
                $tin += (int) ($u['tokens_in'] ?? 0);
                $tout += (int) ($u['tokens_out'] ?? 0);
            }
            echo '<h2>Estimate against actual</h2><table class="widefat" style="max-width:720px"><thead><tr><th></th><th>Estimate</th><th>Actual</th></tr></thead><tbody>'
                . '<tr><th>Audio minutes</th><td>' . esc_html(number_format($e->billedMinutes(), 1)) . '</td><td>' . esc_html(number_format($sec / 60, 1)) . '</td></tr>'
                . '<tr><th>Input tokens</th><td>' . (int) $e->tokensIn . '</td><td>' . (int) $tin . '</td></tr>'
                . '<tr><th>Output tokens</th><td>' . (int) $e->tokensOut . '</td><td>' . (int) $tout . '</td></tr></tbody></table>';
        }
        echo '<h2>Log</h2><ol style="font-family:monospace;font-size:12px">';
        foreach (array_slice($repo->logs($run->id), -100) as $l) {
            echo '<li>' . esc_html((string) wp_date('Y-m-d H:i:s', $l['t']) . ' ' . $l['line']) . '</li>';
        }
        echo '</ol>';
    }

    private static function reviewNotes(mixed $notes): void
    {
        if (!is_array($notes) || $notes === []) {
            return;
        }
        echo '<h2>Review notes</h2><ul>';
        foreach ($notes as $n) {
            if (is_array($n)) {
                echo '<li><strong>' . esc_html((string) ($n['type'] ?? '')) . '</strong>: ' . esc_html((string) ($n['message'] ?? '')) . (isset($n['segment_id']) ? ' [' . esc_html((string) $n['segment_id']) . ']' : '') . '</li>';
            }
        }
        echo '</ul>';
    }

    private static function screenTranscript(Run $run): void
    {
        $repo = Plugin::instance()->repo();
        $t = $repo->transcript($run->id);
        if ($t === null) {
            echo '<p>No transcript yet.</p>';

            return;
        }
        $rev = (int) $repo->meta($run->id, 'revision', 0);
        $h = self::hidden('run', $run->id) . self::hidden('revision', $rev);
        echo '<h1>Transcript: ' . esc_html($run->title()) . '</h1><p><a href="' . esc_url(self::url(['run' => $run->id])) . '">Back to the run</a> &middot; revision ' . (int) $rev . ' &middot; timestamps ' . esc_html($t->timestamps) . ' &middot; speakers ' . esc_html($t->speakersFlag) . '</p>';
        $suggest = $repo->meta($run->id, 'speaker_suggestions', []);
        echo '<h2>Speakers</h2><table class="widefat" style="max-width:900px"><tbody>';
        foreach ($t->speakers as $sp) {
            $hint = '';
            foreach (is_array($suggest) ? $suggest : [] as $sg) {
                if (is_array($sg) && ($sg['speaker'] ?? '') === $sp->label) {
                    $hint = ' Suggested: ' . (string) ($sg['name'] ?? '') . ' (see ' . (string) ($sg['evidence_segment_id'] ?? '') . ', not applied)';
                }
            }
            echo '<tr><th>' . esc_html($sp->id . ' ' . $sp->label) . '</th><td>'
                . self::form('edit', $h . self::hidden('op', 'rename_speaker') . self::hidden('speaker', $sp->id) . '<input type="text" name="name" value="' . esc_attr($sp->name) . '" placeholder="Name"> ', 'Rename')
                . self::form('edit', $h . self::hidden('op', 'merge_speakers') . self::hidden('from', $sp->id) . ' into <input type="text" name="into" class="small-text" placeholder="S1"> ', 'Merge')
                . '<span class="description">' . esc_html($hint) . '</span></td></tr>';
        }
        echo '</tbody></table><h2>Segments</h2>';
        foreach (Turns::build($t, Plugin::instance()->settings()->int('turn_gap_seconds') * 1000) as $turn) {
            echo '<div style="margin:1em 0;padding:.5em;border-left:3px solid #ccd0d4"><strong>' . esc_html($t->speakerDisplay($turn['speaker'])) . '</strong> <code>' . esc_html(Timecode::short($turn['start_ms'])) . '</code>';
            foreach ($turn['paragraphs'] as $para) {
                echo '<p>';
                foreach ($para as $s) {
                    $flags = $s->flags === [] ? '' : ' [' . implode(', ', $s->flags) . ']';
                    echo '<span title="' . esc_attr($s->id . ' ' . Timecode::short($s->startMs) . $flags) . '">' . esc_html($s->text) . '</span> ';
                }
                echo '</p>';
            }
            echo '</div>';
        }
        echo '<h2>Edit a segment</h2><p>'
            . self::form('edit', $h . self::hidden('op', 'reassign') . 'Segment <input type="text" name="segment" class="small-text" placeholder="s0001"> to speaker <input type="text" name="speaker" class="small-text" placeholder="S2"> ', 'Reassign') . '</p><p>'
            . self::form('edit', $h . self::hidden('op', 'split') . 'Segment <input type="text" name="segment" class="small-text" placeholder="s0001"> before word number <input type="number" name="word_index" class="small-text" min="1"> ', 'Split') . '</p><p>'
            . self::form('edit', $h . self::hidden('op', 'text') . 'Segment <input type="text" name="segment" class="small-text" placeholder="s0001"> text <input type="text" name="text" class="large-text"> ', 'Fix text') . '</p>';
    }

    public static function pageSettings(): void
    {
        if (!Access::canManage()) {
            wp_die(esc_html__('You are not allowed to see this page.', 'audio-to-post'), 403);
        }
        $s = Plugin::instance()->settings()->all();
        $mp = is_array($s['multipart']) ? $s['multipart'] : [];
        $field = static fn (string $name, mixed $value, string $type = 'text', string $class = 'regular-text'): string => '<input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr(is_scalar($value) ? (string) $value : '') . '" class="' . esc_attr($class) . '">';
        $row = static fn (string $label, string $html, string $desc = ''): string => '<tr><th>' . esc_html($label) . '</th><td>' . $html . ($desc !== '' ? '<p class="description">' . esc_html($desc) . '</p>' : '') . '</td></tr>';
        $adapters = '<select name="s[adapter]"><option value="">(choose)</option>';
        foreach (Plugin::instance()->adapters()->all() as $id => $a) {
            if ($id !== 'transcript-file') {
                $adapters .= '<option value="' . esc_attr($id) . '"' . selected((string) $s['adapter'], $id, false) . '>' . esc_html($a->label()) . '</option>';
            }
        }
        $adapters .= '</select>';
        $prices = is_array($s['prices']) ? $s['prices'] : [];
        $modelId = (string) $s['model'];
        $extra = '';
        foreach (is_array($mp['extra_fields'] ?? null) ? $mp['extra_fields'] : [] as $f) {
            if (is_array($f)) {
                $extra .= (string) ($f[0] ?? '') . '=' . (string) ($f[1] ?? '') . "\n";
            }
        }
        $html = '<table class="form-table">'
            . $row('Kill switch', KillSwitch::isOn() ? '<strong>ON</strong>' . (KillSwitch::forcedByConstant() ? ' (forced by AUDIOPOST_DISABLED)' : '') : 'off', 'Toggle it with the button below the form or with `wp audio-to-post disable`.')
            . $row('Transcription adapter', $adapters)
            . $row('Endpoint URL', $field('s[multipart][endpoint]', $mp['endpoint'] ?? ''), 'The generic adapter posts multipart/form-data with file and model fields.')
            . $row('Model', $field('s[multipart][model]', $mp['model'] ?? ''))
            . $row('API key', '<input type="password" name="adapter_key" value="" autocomplete="off" class="regular-text" placeholder="' . esc_attr(Settings::mask(Settings::adapterKey(HttpMultipartAdapter::ID))) . '">', 'Leave empty to keep the stored key. The constant AUDIOPOST_ADAPTER_KEY_HTTP_MULTIPART wins.')
            . $row('Limits', $field('s[multipart][max_bytes]', $mp['max_bytes'] ?? '', 'number', 'small-text') . ' bytes, ' . $field('s[multipart][max_seconds]', $mp['max_seconds'] ?? '', 'number', 'small-text') . ' seconds per request', 'Check these against your provider\'s documentation.')
            . $row('Speaker labels', '<label><input type="checkbox" name="s[multipart][diarisation]" value="1"' . checked(!empty($mp['diarisation']), true, false) . '> the endpoint returns speaker labels</label>')
            . $row('Billing rounding', $field('s[multipart][billing_rounding]', $mp['billing_rounding'] ?? 'none', 'text', 'small-text') . ' (none, second or minute), minimum ' . $field('s[multipart][minimum_billable_seconds]', $mp['minimum_billable_seconds'] ?? 0, 'number', 'small-text') . ' s')
            . $row('Response paths', 'text ' . $field('s[multipart][path_text]', $mp['path_text'] ?? '', 'text', 'small-text') . ' segments ' . $field('s[multipart][path_segments]', $mp['path_segments'] ?? '', 'text', 'small-text') . ' words ' . $field('s[multipart][path_words]', $mp['path_words'] ?? '', 'text', 'small-text') . ' speaker field ' . $field('s[multipart][field_speaker]', $mp['field_speaker'] ?? '', 'text', 'small-text'), 'Dotted paths into the JSON reply.')
            . $row('Extra fields', '<textarea name="s[multipart][extra_fields]" rows="3" class="large-text code">' . esc_textarea($extra) . '</textarea>', 'One name=value per line, sent with every request.')
            . $row('Draft model', '<select name="s[model_provider]">' . implode('', array_map(static fn (string $k): string => '<option value="' . esc_attr($k) . '"' . selected((string) $s['model_provider'], $k, false) . '>' . esc_html($k) . '</option>', ['openrouter', 'openai', 'generic'])) . '</select> ' . $field('s[model]', $modelId) . ' base URL ' . $field('s[model_base_url]', $s['model_base_url']), 'Model calls go through llm-json-php unless a site supplies a caller.')
            . $row('Draft model key', '<input type="password" name="model_key" value="" autocomplete="off" class="regular-text" placeholder="' . esc_attr(Settings::mask(Settings::modelKey())) . '">', 'The constant AUDIOPOST_MODEL_KEY wins.')
            . $row('Your prices', 'per audio minute ' . $field('s[prices][minute][' . (string) $s['adapter'] . ']', is_array($prices['minute'] ?? null) ? ($prices['minute'][(string) $s['adapter']] ?? '') : '', 'text', 'small-text')
                . ' per million input tokens ' . $field('s[prices][input][' . $modelId . ']', is_array($prices['input'] ?? null) ? ($prices['input'][$modelId] ?? '') : '', 'text', 'small-text')
                . ' per million output tokens ' . $field('s[prices][output][' . $modelId . ']', is_array($prices['output'] ?? null) ? ($prices['output'][$modelId] ?? '') : '', 'text', 'small-text')
                . ' currency ' . $field('s[currency]', $s['currency'], 'text', 'small-text'), 'No prices are shipped. Without them the estimate shows minutes and tokens only, and the money cap is ignored.')
            . $row('Draft', self::modeSelectNamed('s[default_mode]', (string) $s['default_mode']) . ' <label><input type="checkbox" name="s[append_transcript]" value="1"' . checked((bool) $s['append_transcript'], true, false) . '> append the full transcript</label>')
            . $row('Language', $field('s[language]', $s['language'], 'text', 'small-text'))
            . $row('Chunks', $field('s[chunk_seconds]', $s['chunk_seconds'], 'number', 'small-text') . ' seconds, overlap ' . $field('s[overlap_seconds]', $s['overlap_seconds'], 'number', 'small-text') . ' seconds (0 to 30)')
            . $row('Limits', $field('s[max_bytes]', $s['max_bytes'], 'number') . ' bytes, ' . $field('s[max_seconds]', $s['max_seconds'], 'number', 'small-text') . ' seconds')
            . $row('Estimate', $field('s[words_per_minute]', $s['words_per_minute'], 'number', 'small-text') . ' words per minute, ' . $field('s[characters_per_minute]', $s['characters_per_minute'], 'number', 'small-text') . ' characters per minute (CJK)', 'Default tokens per word: 1.3 for Latin script, 2.5 for Indic and Arabic scripts, 1.0 per character for CJK. These are estimates; compare them with the actual figures on each run screen. Set overrides per language as code=ratio lines below.')
            . $row('Token ratios', '<textarea name="ratio_overrides" rows="3" class="large-text code">' . esc_textarea(implode("\n", array_map(static fn ($k, $v): string => $k . '=' . $v, array_keys((array) $s['ratio_overrides']), (array) $s['ratio_overrides']))) . '</textarea>')
            . $row('Output tokens', 'article ' . $field('s[max_output_article]', $s['max_output_article'], 'number', 'small-text') . ' show notes ' . $field('s[max_output_show_notes]', $s['max_output_show_notes'], 'number', 'small-text') . ' notes ' . $field('s[notes_max_output]', $s['notes_max_output'], 'number', 'small-text') . ' single pass up to ' . $field('s[single_pass_max_tokens]', $s['single_pass_max_tokens'], 'number', 'small-text') . ' tokens, blocks of ' . $field('s[block_words]', $s['block_words'], 'number', 'small-text') . ' words')
            . $row('Daily caps', $field('s[cap_minutes]', $s['cap_minutes'], 'text', 'small-text') . ' audio minutes, ' . $field('s[cap_tokens]', $s['cap_tokens'], 'text', 'small-text') . ' draft tokens, ' . $field('s[cap_money]', $s['cap_money'], 'text', 'small-text') . ' money', 'Empty removes a cap; zero blocks all paid work. The money cap only works when prices are set.')
            . $row('Calls', $field('s[max_attempts]', $s['max_attempts'], 'number', 'small-text') . ' attempts, HTTP timeout ' . $field('s[http_timeout]', $s['http_timeout'], 'number', 'small-text') . ' s, parallel runs ' . $field('s[max_parallel_runs]', $s['max_parallel_runs'], 'number', 'small-text') . ', estimate expiry ' . $field('s[estimate_ttl_hours]', $s['estimate_ttl_hours'], 'number', 'small-text') . ' h')
            . $row('Recordings', 'keep after the transcript ' . $field('s[keep_recording_hours]', $s['keep_recording_hours'], 'number', 'small-text') . ' h, after a failure ' . $field('s[keep_failed_hours]', $s['keep_failed_hours'], 'number', 'small-text') . ' h <label><input type="checkbox" name="s[remove_recordings_on_uninstall]" value="1"' . checked((bool) $s['remove_recordings_on_uninstall'], true, false) . '> remove audio copies on uninstall</label>', sprintf('Now: audio copies are deleted %d hour(s) after a run finishes and %d hour(s) after a run fails. Nothing else deletes them, and every deletion is logged.', (int) $s['keep_recording_hours'], (int) $s['keep_failed_hours']))
            . $row('Runs and transcripts', 'keep ' . $field('s[retention_days]', $s['retention_days'], 'number', 'small-text') . ' days (0 keeps for ever)');
        foreach (Prompts::defaults() as $key => $default) {
            $current = is_array($s['prompts']) && is_string($s['prompts'][$key] ?? null) ? $s['prompts'][$key] : $default;
            $html .= $row('Prompt ' . $key, '<textarea name="s[prompts][' . esc_attr($key) . ']" rows="6" class="large-text code">' . esc_textarea($current) . '</textarea><label><input type="checkbox" name="restore[' . esc_attr($key) . ']" value="1"> restore default</label>', 'Variables: {title} {source_label} {duration} {language} {speakers} {transcript} {notes} {mode}');
        }
        $html .= '</table>';
        echo '<div class="wrap"><h1>Audio to post settings</h1><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="audiopost_settings">' . wp_nonce_field('audiopost_settings', '_audiopost_nonce', true, false)
            . $html . '<p><button class="button button-primary" type="submit">Save settings</button></p></form>'
            . '<h2>Kill switch</h2><p>' . self::form('switch', self::hidden('on', KillSwitch::isOn() ? 0 : 1), KillSwitch::isOn() ? 'Turn the kill switch off' : 'Turn the kill switch on (stop all paid calls)', 'button') . '</p></div>';
    }

    private static function modeSelectNamed(string $name, string $selected): string
    {
        return str_replace('<select name="mode">', '<select name="' . esc_attr($name) . '">', self::modeSelect($selected));
    }

    public static function pageStatus(): void
    {
        if (!Access::canManage()) {
            wp_die(esc_html__('You are not allowed to see this page.', 'audio-to-post'), 403);
        }
        $p = Plugin::instance();
        echo '<div class="wrap"><h1>Audio to post status</h1><table class="widefat" style="max-width:900px"><tbody>';
        $rows = ['Kill switch' => KillSwitch::isOn() ? 'ON' : 'off'];
        foreach ($p->guard()->today() as $unit => $row) {
            $rows['Today: ' . $unit] = 'used ' . $row['used'] . ($row['cap'] === null ? ', no cap' : ', cap ' . $row['cap'] . ', left ' . $row['left']);
        }
        foreach (RunState::ALL as $st) {
            $n = count($p->repo()->find([$st], 1000));
            if ($n > 0) {
                $rows['Runs ' . $st] = (string) $n;
            }
        }
        foreach ($p->adapters()->all() as $id => $a) {
            $rows['Adapter ' . $id] = $a->configurationProblems() === [] ? 'configured' : implode(' ', $a->configurationProblems());
        }
        $ticks = Options::fresh('audiopost_ticks', []);
        $ticks = is_array($ticks) ? $ticks : [];
        $rows['Last tick'] = $ticks === [] ? 'never' : wp_date('Y-m-d H:i:s', (int) max($ticks));
        $rows['Ticks in the past hour'] = (string) count($ticks);
        $rows['Private directory'] = self::privateDirCheck();
        foreach ($rows as $k => $v) {
            echo '<tr><th>' . esc_html($k) . '</th><td>' . esc_html((string) $v) . '</td></tr>';
        }
        echo '</tbody></table><h2>Audio files</h2><table class="widefat"><thead><tr><th>File</th><th>Run</th><th>Kind</th><th>Due</th><th>Failed deletions</th></tr></thead><tbody>';
        foreach ($p->files()->registry() as $id => $e) {
            echo '<tr><td><code>' . esc_html($id) . '</code></td><td>' . (int) $e['run'] . '</td><td>' . esc_html($e['kind']) . '</td><td>' . esc_html($e['due'] === null ? 'run not final' : wp_date('Y-m-d H:i', $e['due']) . ' (' . $e['reason'] . ')') . '</td><td>' . (int) $e['failures'] . '</td></tr>';
        }
        foreach ($p->files()->orphans() as $o) {
            echo '<tr><td><code>' . esc_html($o['id']) . '</code></td><td>orphan</td><td>' . esc_html((string) size_format($o['bytes'])) . '</td><td>' . esc_html((string) wp_date('Y-m-d H:i', $o['mtime'] + max(PrivateStorage::ORPHAN_MIN_AGE, $p->settings()->int('keep_recording_hours') * 3600))) . '</td><td></td></tr>';
        }
        echo '</tbody></table>';
        $log = Options::fresh('audiopost_orphan_log', []);
        if (is_array($log) && $log !== []) {
            echo '<h2>Deletions without a run</h2><ul>';
            foreach (array_reverse($log) as $l) {
                if (is_array($l)) {
                    echo '<li>' . esc_html((string) wp_date('Y-m-d H:i', (int) ($l['t'] ?? 0)) . ' ' . (string) ($l['line'] ?? '')) . '</li>';
                }
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    /** Warns when a file in the private directory is reachable over HTTP. */
    private static function privateDirCheck(): string
    {
        $dir = Plugin::instance()->files()->dir();
        $probe = $dir . '/probe-' . substr(md5((string) wp_rand()), 0, 8) . '.txt';
        file_put_contents($probe, 'audio-to-post probe');
        $uploads = wp_upload_dir(null, false);
        $url = rtrim((string) $uploads['baseurl'], '/') . '/audio-to-post/' . basename($probe);
        $res = wp_remote_get($url, ['timeout' => 5, 'redirection' => 0]);
        unlink($probe);
        if (!is_wp_error($res) && (int) wp_remote_retrieve_response_code($res) === 200 && str_contains((string) wp_remote_retrieve_body($res), 'audio-to-post probe')) {
            return 'WARNING: files in ' . $dir . ' are reachable over HTTP. Add a deny rule (see the README for nginx).';
        }

        return 'not reachable over HTTP (good): ' . $dir;
    }

    public static function notices(): void
    {
        if (!Access::canCreate()) {
            return;
        }
        $notes = [];
        if (KillSwitch::isOn()) {
            $notes[] = 'Audio to post: the kill switch is on. No transcription or model call will start.';
        }
        if (Options::fresh('audiopost_notice_cap', '') === Plugin::instance()->clock()->localDay()) {
            $notes[] = 'Audio to post: a daily cap was reached today. Paused runs resume after local midnight.';
        }
        $prov = Options::fresh('audiopost_notice_provider', null);
        if (is_array($prov) && (int) ($prov['until'] ?? 0) > Plugin::instance()->clock()->now()) {
            $notes[] = sprintf('Audio to post: %s answered %s. Its other runs are paused for 30 minutes. Check the key and the account.', (string) ($prov['adapter'] ?? ''), (string) ($prov['code'] ?? ''));
        }
        $ticks = Options::fresh('audiopost_ticks', []);
        $last = is_array($ticks) && $ticks !== [] ? (int) max($ticks) : 0;
        if ($last < Plugin::instance()->clock()->now() - 600 && Plugin::instance()->repo()->find([RunState::NEW, RunState::FETCHING, RunState::QUEUED, RunState::TRANSCRIBING, RunState::DRAFTING], 1) !== []) {
            $notes[] = 'Audio to post: work is queued but WP-Cron has not ticked for 10 minutes. Run `wp audio-to-post run` from a system cron.';
        }
        foreach ($notes as $n) {
            echo '<div class="notice notice-warning"><p>' . esc_html($n) . '</p></div>';
        }
    }

    public static function metaBox(\WP_Post $post): void
    {
        if ((int) get_post_meta($post->ID, '_audiopost_run_id', true) <= 0) {
            return;
        }
        add_meta_box('audiopost-review', 'Audio to post: review notes', [self::class, 'renderMetaBox'], 'post', 'side');
    }

    public static function renderMetaBox(\WP_Post $post): void
    {
        $runId = (int) get_post_meta($post->ID, '_audiopost_run_id', true);
        $raw = get_post_meta($post->ID, '_audiopost_review_notes', true);
        $notes = is_string($raw) ? json_decode($raw, true) : [];
        echo '<p><a href="' . esc_url(self::url(['run' => $runId])) . '">Run ' . (int) $runId . '</a></p>';
        if (!is_array($notes) || $notes === []) {
            echo '<p>No review notes.</p>';

            return;
        }
        echo '<ul>';
        foreach ($notes as $n) {
            if (is_array($n)) {
                echo '<li><strong>' . esc_html((string) ($n['type'] ?? '')) . '</strong>: ' . esc_html((string) ($n['message'] ?? '')) . '</li>';
            }
        }
        echo '</ul>';
    }

    // Handlers.

    public static function handleNewRun(): void
    {
        self::verify('new_run');
        $kind = sanitize_key((string) wp_unslash($_POST['kind'] ?? ''));
        $options = [
            'mode' => sanitize_key((string) wp_unslash($_POST['mode'] ?? '')),
            'language' => sanitize_text_field((string) wp_unslash($_POST['language'] ?? '')),
            'rights_confirmed' => !empty($_POST['rights_confirmed']),
        ];
        $source = null;
        if ($kind === 'feed') {
            wp_safe_redirect(self::url(['feed' => esc_url_raw((string) wp_unslash($_POST['feed_url'] ?? ''))]));
            exit;
        }
        switch ($kind) {
            case 'upload':
            case 'transcript':
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
                $field = $kind === 'upload' ? 'audio' : 'transcript_file';
                // Check the type before anything lands in the Media Library.
                $name = isset($_FILES[$field]['name']) && is_string($_FILES[$field]['name']) ? sanitize_file_name(wp_unslash($_FILES[$field]['name'])) : '';
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $type = wp_check_filetype($name);
                $ok = $kind === 'upload'
                    ? is_string($type['type']) && (str_starts_with($type['type'], 'audio/') || $type['type'] === 'application/ogg')
                    : in_array($ext, ['vtt', 'srt', 'json'], true);
                if (!$ok) {
                    self::back(0, $kind === 'upload' ? 'Upload an audio file (MP3, WAV, M4A, Ogg, FLAC or WebM audio).' : 'Upload a WebVTT, SRT or JSON transcript.', 'error');
                }
                $att = media_handle_upload($field, 0);
                if (is_wp_error($att)) {
                    self::back(0, $att->get_error_message(), 'error');
                }
                $att = (int) $att;
                $source = $kind === 'upload' ? ['kind' => Source::ATTACHMENT, 'attachment_id' => (int) $att] : ['kind' => Source::TRANSCRIPT_FILE, 'transcript_attachment_id' => (int) $att, 'file_name' => get_the_title((int) $att)];
                break;
            case 'attachment':
                $source = ['kind' => Source::ATTACHMENT, 'attachment_id' => absint($_POST['attachment_id'] ?? 0)];
                break;
            case 'url':
                $source = ['kind' => Source::URL, 'url' => esc_url_raw((string) wp_unslash($_POST['url'] ?? ''))];
                break;
            case 'feed_episode':
                $source = ['kind' => Source::FEED_EPISODE];
                foreach (['feed_url', 'guid', 'episode_title', 'published', 'enclosure_url', 'transcript_url', 'transcript_type'] as $k) {
                    if (isset($_POST[$k])) {
                        $source[$k] = str_ends_with($k, '_url') ? esc_url_raw((string) wp_unslash($_POST[$k])) : sanitize_text_field((string) wp_unslash($_POST[$k]));
                    }
                }
                $source['use_transcript'] = !empty($_POST['use_transcript']);
                break;
        }
        if ($source === null) {
            self::back(0, 'Pick a source.', 'error');
        }
        try {
            $run = Plugin::instance()->runs()->create($source, $options, get_current_user_id());
            self::back($run->id, 'Run created. It is measured in the background; reload in a moment for the estimate.');
        } catch (AudioToPostException $e) {
            if ($e->errorCode() === 'feed_url') {
                wp_safe_redirect(self::url(['feed' => (string) ($source['url'] ?? '')]));
                exit;
            }
            self::back(0, $e->errorCode() . ': ' . $e->getMessage(), 'error');
        }
    }

    public static function handleConfirm(): void
    {
        self::verify('confirm');
        $run = self::postedRun();
        try {
            $res = Plugin::instance()->runs()->confirm($run->id, sanitize_text_field((string) wp_unslash($_POST['fingerprint'] ?? '')), !empty($_POST['reuse']), get_current_user_id());
            self::back($run->id, $res['queued_for_tomorrow'] ? 'Queued for tomorrow.' : 'Started.');
        } catch (AudioToPostException $e) {
            self::back($run->id, $e->getMessage(), 'error');
        }
    }

    public static function handleCancel(): void
    {
        self::verify('cancel');
        $run = self::postedRun();
        self::back($run->id, Plugin::instance()->runs()->cancel($run->id) ? 'Cancel requested.' : 'This run cannot be cancelled now.');
    }

    public static function handleRetry(): void
    {
        self::verify('retry');
        $run = self::postedRun();
        try {
            $fp = sanitize_text_field((string) wp_unslash($_POST['fingerprint'] ?? ''));
            Plugin::instance()->runs()->retry($run->id, $fp !== '' ? $fp : null);
            self::back($run->id, 'Queued for retry.');
        } catch (AudioToPostException $e) {
            self::back($run->id, $e->getMessage(), 'error');
        }
    }

    public static function handleReestimate(): void
    {
        self::verify('reestimate');
        $run = self::postedRun();
        try {
            Plugin::instance()->runs()->reestimate($run->id);
            self::back($run->id, 'New estimate ready.');
        } catch (AudioToPostException $e) {
            self::back($run->id, $e->getMessage(), 'error');
        }
    }

    public static function handleDraft(): void
    {
        self::verify('draft');
        $run = self::postedRun();
        try {
            Plugin::instance()->runs()->createDraft($run->id, sanitize_key((string) wp_unslash($_POST['mode'] ?? '')));
            self::back($run->id, 'Draft queued.');
        } catch (AudioToPostException $e) {
            self::back($run->id, $e->getMessage(), 'error');
        }
    }

    public static function handleEdit(): void
    {
        self::verify('edit');
        $run = self::postedRun();
        $edit = ['op' => sanitize_key((string) wp_unslash($_POST['op'] ?? ''))];
        foreach (['speaker', 'name', 'from', 'into', 'segment', 'text'] as $k) {
            if (isset($_POST[$k])) {
                $edit[$k] = sanitize_text_field((string) wp_unslash($_POST[$k]));
            }
        }
        if (isset($_POST['word_index'])) {
            $edit['word_index'] = absint($_POST['word_index']);
        }
        try {
            Plugin::instance()->runs()->editTranscript($run->id, [$edit], absint($_POST['revision'] ?? 0));
            wp_safe_redirect(self::url(['run' => $run->id, 'transcript' => 1]));
            exit;
        } catch (AudioToPostException $e) {
            self::back($run->id, $e->getMessage(), 'error');
        }
    }

    public static function handleExport(): void
    {
        check_admin_referer('audiopost_export', '_audiopost_nonce');
        $run = self::postedRun();
        $format = sanitize_key((string) wp_unslash($_GET['format'] ?? 'json'));
        try {
            $body = Plugin::instance()->runs()->export($run->id, $format, !empty($_GET['original']));
        } catch (AudioToPostException $e) {
            self::back($run->id, $e->getMessage(), 'error');
        }
        nocache_headers();
        header('Content-Type: ' . Exporter::mime($format) . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="run-' . (int) $run->id . '.' . $format . '"');
        header('X-Content-Type-Options: nosniff');
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- a file download, not HTML.
        exit;
    }

    public static function handleSettings(): void
    {
        self::verify('settings', true);
        $in = isset($_POST['s']) && is_array($_POST['s']) ? wp_unslash($_POST['s']) : [];
        $in = is_array($in) ? $in : [];
        $restore = isset($_POST['restore']) && is_array($_POST['restore']) ? array_keys($_POST['restore']) : [];
        if (is_array($in['prompts'] ?? null)) {
            foreach (Prompts::defaults() as $k => $default) {
                if (in_array($k, $restore, true) || (is_string($in['prompts'][$k] ?? null) && trim(str_replace("\r\n", "\n", $in['prompts'][$k])) === trim($default))) {
                    unset($in['prompts'][$k]);
                }
            }
        }
        foreach (['append_transcript', 'remove_recordings_on_uninstall'] as $b) {
            $in[$b] = !empty($in[$b]);
        }
        if (is_array($in['multipart'] ?? null)) {
            $in['multipart']['diarisation'] = !empty($in['multipart']['diarisation']);
        }
        $ratios = [];
        foreach (preg_split('/\R/', (string) wp_unslash($_POST['ratio_overrides'] ?? '')) ?: [] as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $ratios[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }
        $in['ratio_overrides'] = $ratios;
        Plugin::instance()->settings()->save($in);
        $ak = trim((string) wp_unslash($_POST['adapter_key'] ?? ''));
        if ($ak !== '') {
            Settings::saveSecret('adapter', $ak, HttpMultipartAdapter::ID);
        }
        $mk = trim((string) wp_unslash($_POST['model_key'] ?? ''));
        if ($mk !== '') {
            Settings::saveSecret('model', $mk);
        }
        wp_safe_redirect(add_query_arg(['page' => self::SLUG . '-settings', 'updated' => 1], admin_url('admin.php')));
        exit;
    }

    public static function handleSwitch(): void
    {
        self::verify('switch', true);
        $user = wp_get_current_user();
        KillSwitch::set(!empty($_POST['on']), 'user:' . $user->user_login);
        wp_safe_redirect(add_query_arg(['page' => self::SLUG . '-settings'], admin_url('admin.php')));
        exit;
    }

    public static function handleDelete(): void
    {
        self::verify('delete');
        $run = self::postedRun();
        if (!in_array($run->state, RunState::FINAL, true)) {
            self::back($run->id, 'Only a finished run can be deleted.', 'error');
        }
        Plugin::instance()->runs()->delete($run->id);
        self::back(0, 'Run deleted. Drafts were kept; audio copies follow the retention settings.');
    }
}
