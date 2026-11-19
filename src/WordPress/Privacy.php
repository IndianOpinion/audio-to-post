<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

use IndianOpinion\AudioToPost\Plugin;

/**
 * Personal data exporter and eraser, and the privacy policy text
 * (section 8.6). Erasure deletes a user's runs; drafts are kept, and
 * audio copies follow the retention settings and are reported.
 */
final class Privacy
{
    /**
     * @param array<string, mixed> $exporters
     *
     * @return array<string, mixed>
     */
    public static function exporters(array $exporters): array
    {
        $exporters['audio-to-post'] = ['exporter_friendly_name' => 'Audio to post runs', 'callback' => [self::class, 'export']];

        return $exporters;
    }

    /**
     * @param array<string, mixed> $erasers
     *
     * @return array<string, mixed>
     */
    public static function erasers(array $erasers): array
    {
        $erasers['audio-to-post'] = ['eraser_friendly_name' => 'Audio to post runs', 'callback' => [self::class, 'erase']];

        return $erasers;
    }

    public static function policy(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content('Audio to post', wp_kses_post(
            '<p>When an editor turns a recording into a draft, the recording is sent to the speech-to-text service the site operator configured, and the transcript text is sent to the text model the operator configured. Recordings and transcripts may contain the voices and words of the people recorded. A private copy of the recording is kept on this site for the time set in the plugin\'s retention settings and then deleted. Transcripts are kept with the run for the retention period.</p>'
        ));
    }

    /**
     * @return array{data: list<array<string, mixed>>, done: bool}
     */
    public static function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if ($user === false) {
            return ['data' => [], 'done' => true];
        }
        $p = Plugin::instance();
        $data = [];
        $ids = $p->repo()->find([], 20, (int) $user->ID, 'ASC', ($page - 1) * 20);
        foreach ($ids as $id) {
            $run = $p->repo()->get($id);
            if ($run === null) {
                continue;
            }
            $t = $p->repo()->transcript($id);
            $data[] = [
                'group_id' => 'audio-to-post',
                'group_label' => 'Audio to post runs',
                'item_id' => 'audiopost-run-' . $id,
                'data' => [
                    ['name' => 'Run', 'value' => (string) $id],
                    ['name' => 'Source', 'value' => $run->source->label()],
                    ['name' => 'State', 'value' => $run->state],
                    ['name' => 'Created', 'value' => gmdate('c', $run->createdAt)],
                    ['name' => 'Transcript', 'value' => $t === null ? '' : $t->text()],
                ],
            ];
        }

        return ['data' => $data, 'done' => count($ids) < 20];
    }

    /**
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    public static function erase(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if ($user === false) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }
        $p = Plugin::instance();
        $ids = $p->repo()->find([], 20, (int) $user->ID);
        $messages = [];
        $held = [];
        foreach ($ids as $id) {
            $held += $p->files()->filesOf($id);
            $p->repo()->delete($id);
        }
        $registry = $p->files()->registry();
        foreach (array_keys($held) as $fid) {
            $e = $registry[$fid] ?? null;
            if ($e !== null && $e['due'] !== null) {
                $messages[] = sprintf('Audio copy %s is kept until %s under %s; lower the retention setting or run purge to remove it sooner.', $fid, gmdate('c', $e['due']), $e['reason']);
            }
        }

        return ['items_removed' => $ids !== [], 'items_retained' => $messages !== [], 'messages' => $messages, 'done' => count($ids) < 20];
    }
}
