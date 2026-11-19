<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

use IndianOpinion\AudioToPost\AudioToPostException;
use IndianOpinion\AudioToPost\Plugin;
use IndianOpinion\AudioToPost\Run;
use IndianOpinion\AudioToPost\RunState;
use IndianOpinion\AudioToPost\Transcript\Exporter;

/**
 * REST API, namespace `audio-to-post/v1` (section 5.7). Cookie requests
 * carry the core REST nonce, which WordPress checks before any route;
 * every route then checks the capability and run ownership.
 */
final class Rest
{
    public const NS = 'audio-to-post/v1';

    public static function register(): void
    {
        $create = [self::class, 'canCreate'];
        $own = [self::class, 'canActOnRun'];
        $id = ['id' => ['validate_callback' => static fn ($v): bool => is_numeric($v) && (int) $v > 0]];
        register_rest_route(self::NS, '/runs', [
            ['methods' => 'POST', 'callback' => [self::class, 'create'], 'permission_callback' => $create],
            ['methods' => 'GET', 'callback' => [self::class, 'list'], 'permission_callback' => $create],
        ]);
        register_rest_route(self::NS, '/runs/(?P<id>\d+)', [
            ['methods' => 'GET', 'callback' => [self::class, 'show'], 'permission_callback' => $own, 'args' => $id],
        ]);
        foreach (['confirm', 'cancel', 'retry', 'drafts'] as $action) {
            register_rest_route(self::NS, '/runs/(?P<id>\d+)/' . $action, [
                ['methods' => 'POST', 'callback' => [self::class, $action], 'permission_callback' => $own, 'args' => $id],
            ]);
        }
        register_rest_route(self::NS, '/runs/(?P<id>\d+)/transcript', [
            ['methods' => 'GET', 'callback' => [self::class, 'transcript'], 'permission_callback' => $own, 'args' => $id],
            ['methods' => 'PATCH', 'callback' => [self::class, 'edit'], 'permission_callback' => $own, 'args' => $id],
        ]);
        register_rest_route(self::NS, '/feed-episodes', [
            ['methods' => 'GET', 'callback' => [self::class, 'feed'], 'permission_callback' => $create],
        ]);
    }

    public static function canCreate(): bool
    {
        return Access::canCreate();
    }

    public static function canActOnRun(\WP_REST_Request $r): bool
    {
        return Access::canAct(Plugin::instance()->repo()->get((int) $r['id']));
    }

    public static function create(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $source = $r->get_param('source');
        $options = $r->get_param('options');
        if (!is_array($source)) {
            return new \WP_Error('invalid_source', 'The body needs a source object.', ['status' => 400]);
        }
        $options = is_array($options) ? $options : [];
        $options['rights_confirmed'] = filter_var($options['rights_confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return self::wrap(static function () use ($source, $options): \WP_REST_Response {
            $run = Plugin::instance()->runs()->create($source, $options, get_current_user_id());

            return new \WP_REST_Response(self::runData($run), 201);
        });
    }

    public static function list(\WP_REST_Request $r): \WP_REST_Response
    {
        $state = $r->get_param('state');
        $states = is_string($state) && in_array($state, RunState::ALL, true) ? [$state] : [];
        $page = max(1, (int) ($r->get_param('page') ?? 1));
        $author = Access::canManage() ? null : get_current_user_id();
        $repo = Plugin::instance()->repo();
        $out = [];
        foreach ($repo->find($states, 50, $author, 'DESC', ($page - 1) * 50) as $id) {
            $run = $repo->get($id);
            if ($run !== null) {
                $out[] = $run->toArray();
            }
        }

        return new \WP_REST_Response($out);
    }

    public static function show(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $run = Plugin::instance()->repo()->get((int) $r['id']);

        return $run === null ? new \WP_Error('not_found', 'Run not found.', ['status' => 404]) : new \WP_REST_Response(self::runData($run));
    }

    public static function confirm(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $fp = $r->get_param('fingerprint');
        $reuse = filter_var($r->get_param('reuse') ?? false, FILTER_VALIDATE_BOOLEAN);

        return self::wrap(static function () use ($r, $fp, $reuse): \WP_REST_Response {
            $res = Plugin::instance()->runs()->confirm((int) $r['id'], is_string($fp) ? $fp : '', $reuse, get_current_user_id());

            return new \WP_REST_Response(['state' => $res['state'], 'queued_for_tomorrow' => $res['queued_for_tomorrow']]);
        }, (int) $r['id']);
    }

    public static function cancel(\WP_REST_Request $r): \WP_REST_Response
    {
        return new \WP_REST_Response(['cancelled' => Plugin::instance()->runs()->cancel((int) $r['id'])]);
    }

    public static function retry(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $fp = $r->get_param('fingerprint');

        return self::wrap(static function () use ($r, $fp): \WP_REST_Response {
            $res = Plugin::instance()->runs()->retry((int) $r['id'], is_string($fp) && $fp !== '' ? $fp : null);

            return new \WP_REST_Response(['state' => $res['state'], 'estimate' => $res['estimate']->toArray()]);
        }, (int) $r['id']);
    }

    public static function drafts(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $mode = $r->get_param('mode');

        return self::wrap(static function () use ($r, $mode): \WP_REST_Response {
            $job = Plugin::instance()->runs()->createDraft((int) $r['id'], is_string($mode) ? $mode : '');

            return new \WP_REST_Response(['job' => $job], 202);
        });
    }

    public static function transcript(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $format = $r->get_param('format');
        $format = is_string($format) && in_array($format, Exporter::FORMATS, true) ? $format : 'json';
        $original = filter_var($r->get_param('original') ?? false, FILTER_VALIDATE_BOOLEAN);

        return self::wrap(static function () use ($r, $format, $original): \WP_REST_Response {
            $id = (int) $r['id'];
            $body = Plugin::instance()->runs()->export($id, $format, $original);
            if ($format === 'json') {
                return new \WP_REST_Response(['revision' => (int) Plugin::instance()->repo()->meta($id, 'revision', 0), 'transcript' => json_decode($body, true)]);
            }

            return new \WP_REST_Response(['format' => $format, 'body' => $body]);
        });
    }

    public static function edit(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $edits = $r->get_param('edits');
        $rev = $r->get_param('revision');
        if (!is_array($edits) || !is_numeric($rev)) {
            return new \WP_Error('invalid_edit', 'Send edits (a list) and the revision you edited.', ['status' => 400]);
        }

        return self::wrap(static function () use ($r, $edits, $rev): \WP_REST_Response {
            $id = (int) $r['id'];
            /** @var list<array<string, mixed>> $list */
            $list = array_values(array_filter($edits, 'is_array'));
            $t = Plugin::instance()->runs()->editTranscript($id, $list, (int) $rev);

            return new \WP_REST_Response(['revision' => (int) Plugin::instance()->repo()->meta($id, 'revision', 0), 'transcript' => $t->toArray()]);
        });
    }

    public static function feed(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $url = $r->get_param('url');

        return self::wrap(static fn (): \WP_REST_Response => new \WP_REST_Response(Plugin::instance()->runs()->feedEpisodes(is_string($url) ? $url : '')));
    }

    /**
     * @return array<string, mixed>
     */
    public static function runData(Run $run): array
    {
        $p = Plugin::instance();
        $data = $run->toArray();
        $data['today'] = $p->guard()->today();
        $data['review_notes'] = $p->repo()->meta($run->id, 'review_notes', []);
        $data['speaker_suggestions'] = $p->repo()->meta($run->id, 'speaker_suggestions', []);
        $data['modes_unavailable'] = $p->drafts()->callerAvailable($run) ? [] : ['article' => 'no_llm_caller', 'show_notes' => 'no_llm_caller'];
        $data['kill_switch'] = KillSwitch::isOn();

        return $data;
    }

    /**
     * @param callable(): \WP_REST_Response $fn
     */
    private static function wrap(callable $fn, int $runId = 0): \WP_REST_Response|\WP_Error
    {
        try {
            return $fn();
        } catch (AudioToPostException $e) {
            $err = audiopost_error($e);
            if ($e->errorCode() === 'estimate_stale' && $runId > 0) {
                $est = Plugin::instance()->repo()->meta($runId, 'estimate');
                $err->add_data(['status' => 409, 'estimate' => $est]);
            }

            return $err;
        }
    }
}
