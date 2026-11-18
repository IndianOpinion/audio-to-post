<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Http;

use IndianOpinion\AudioToPost\SourceException;

/**
 * Reads RSS 2.0 podcast feeds (case 3) and the open Podcasting 2.0
 * `podcast:transcript` element (case 4). Parsed as XML with network
 * access and entity expansion off.
 */
final class FeedParser
{
    public const LIMIT = 50;
    private const NS_ITUNES = 'http://www.itunes.com/dtds/podcast-1.0.dtd';
    private const NS_PODCAST = 'https://podcastindex.org/namespace/1.0';

    /**
     * @return list<array{guid: string, title: string, published: string, published_ts: int, duration: ?int, enclosure_url: string, enclosure_size: ?int, enclosure_type: string, transcript_url: ?string, transcript_type: ?string}>
     *
     * @throws SourceException not_a_feed
     */
    public static function episodes(string $xml, int $limit = self::LIMIT): array
    {
        if (stripos($xml, '<!ENTITY') !== false || stripos($xml, '<!DOCTYPE') !== false) {
            throw new SourceException('not_a_feed', 'Feeds with a document type declaration are not accepted.');
        }
        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($doc === false || $doc->getName() !== 'rss' || !isset($doc->channel)) {
            throw new SourceException('not_a_feed', 'This is not an RSS 2.0 feed.');
        }
        $out = [];
        foreach ($doc->channel->item as $item) {
            $enc = $item->enclosure;
            $url = $enc !== null ? trim((string) $enc['url']) : '';
            if ($url === '') {
                continue;
            }
            $ts = strtotime(trim((string) $item->pubDate));
            $itunes = $item->children(self::NS_ITUNES);
            $podcast = $item->children(self::NS_PODCAST);
            $transcriptUrl = null;
            $transcriptType = null;
            foreach ($podcast->transcript as $t) {
                // Unprefixed attributes of a namespaced element need attributes().
                $attrs = $t->attributes();
                $tUrl = trim((string) ($attrs['url'] ?? ''));
                $type = self::transcriptType((string) ($attrs['type'] ?? ''), $tUrl);
                if ($type !== null && $tUrl !== '') {
                    $transcriptUrl = $tUrl;
                    $transcriptType = $type;
                    break;
                }
            }
            $guid = trim((string) $item->guid);
            $size = trim((string) $enc['length']);
            $out[] = [
                'guid' => $guid !== '' ? $guid : $url,
                'title' => trim((string) $item->title),
                'published' => $ts === false ? '' : gmdate('c', $ts),
                'published_ts' => $ts === false ? 0 : $ts,
                'duration' => self::duration(trim((string) $itunes->duration)),
                'enclosure_url' => $url,
                'enclosure_size' => ctype_digit($size) && (int) $size > 0 ? (int) $size : null,
                'enclosure_type' => trim((string) $enc['type']),
                'transcript_url' => $transcriptUrl,
                'transcript_type' => $transcriptType,
            ];
        }
        usort($out, static fn (array $a, array $b): int => $b['published_ts'] <=> $a['published_ts']);

        return array_slice($out, 0, max(1, $limit));
    }

    /** Seconds from `hh:mm:ss`, `mm:ss` or plain seconds. */
    public static function duration(string $v): ?int
    {
        if ($v === '') {
            return null;
        }
        if (ctype_digit($v)) {
            return (int) $v;
        }
        if (preg_match('/^(?:(\d+):)?(\d{1,2}):(\d{2})$/', $v, $m) === 1) {
            return ((int) $m[1]) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
        }

        return null;
    }

    /** vtt, srt or json when readable by the transcript-file adapter. */
    public static function transcriptType(string $mime, string $url): ?string
    {
        $mime = strtolower(trim($mime));
        $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return match (true) {
            $mime === 'text/vtt' || $ext === 'vtt' => 'vtt',
            in_array($mime, ['application/x-subrip', 'application/srt', 'text/srt'], true) || $ext === 'srt' => 'srt',
            $mime === 'application/json' || $ext === 'json' => 'json',
            default => null,
        };
    }
}
