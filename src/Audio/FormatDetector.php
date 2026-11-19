<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Audio;

use IndianOpinion\AudioToPost\AudioException;
use IndianOpinion\AudioToPost\SourceException;

/**
 * Decides what a file is from its first bytes (case 12). Magic bytes win
 * over the declared content type. Headers about media are hints only.
 */
final class FormatDetector
{
    public const MP3 = 'mp3';
    public const WAV = 'wav';
    public const OGG = 'ogg';
    public const FLAC = 'flac';
    public const MP4 = 'm4a';
    public const WEBM = 'webm';
    public const AUDIO_FORMATS = [self::MP3, self::WAV, self::OGG, self::FLAC, self::MP4, self::WEBM];

    public const HTML = 'html';
    public const RSS = 'rss';
    public const XML = 'xml';
    public const UNKNOWN = 'unknown';

    public const NOT_AUDIO_MESSAGE = 'This looks like a web page. Paste the audio file link or the podcast feed link instead.';

    /**
     * Classify the first bytes of a file.
     */
    public static function sniff(string $head): string
    {
        if (strlen($head) >= 12 && str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WAVE') {
            return self::WAV;
        }
        if (str_starts_with($head, 'OggS')) {
            return self::OGG;
        }
        if (str_starts_with($head, 'fLaC')) {
            return self::FLAC;
        }
        if (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp') {
            return self::MP4;
        }
        if (str_starts_with($head, "\x1A\x45\xDF\xA3")) {
            return self::WEBM;
        }
        if (str_starts_with($head, 'ID3')) {
            return self::MP3;
        }
        if (strlen($head) >= 4 && Mp3Reader::parseHeader(substr($head, 0, 4)) !== null) {
            return self::MP3;
        }
        $text = ltrim(str_starts_with($head, "\xEF\xBB\xBF") ? substr($head, 3) : $head);
        if ($text !== '' && $text[0] === '<') {
            $lower = strtolower(substr($text, 0, 4096));
            if (str_contains($lower, '<html') || str_contains($lower, '<!doctype html') || str_contains($lower, '<head') || str_contains($lower, '<body')) {
                return self::HTML;
            }
            if (preg_match('/<rss[\s>]/', $lower) === 1) {
                return self::RSS;
            }

            return self::XML;
        }

        return self::UNKNOWN;
    }

    /**
     * Decide the audio format of a file or throw the right error.
     *
     * @throws SourceException       not_audio, feed_url (an RSS body)
     * @throws AudioException        video_not_supported, unsupported_format
     */
    public static function detect(string $path, ?string $contentType = null): string
    {
        $head = self::head($path, 4096);
        $kind = self::sniff($head);
        $ct = strtolower(trim(explode(';', (string) $contentType)[0]));
        if ($kind === self::RSS) {
            throw new SourceException('feed_url', 'This is a podcast feed. Pick an episode from the feed instead.');
        }
        if ($kind === self::HTML || $kind === self::XML) {
            throw new SourceException('not_audio', self::NOT_AUDIO_MESSAGE);
        }
        if ($kind === self::UNKNOWN) {
            if (str_starts_with($ct, 'video/')) {
                throw new AudioException('video_not_supported', 'Video files are not supported. Upload the audio only.');
            }
            if ($ct === 'text/html' || $ct === 'application/xhtml+xml') {
                throw new SourceException('not_audio', self::NOT_AUDIO_MESSAGE);
            }
            throw new SourceException('not_audio', 'The file is not a recognised audio format (MP3, WAV, Ogg, FLAC, M4A or WebM).');
        }
        if ($kind === self::MP4 && self::mp4HasVideo($path) === true) {
            throw new AudioException('video_not_supported', 'This MP4 file holds a video track. Upload the audio only.');
        }
        if ($kind === self::WEBM && self::webmHasVideo($path) === true) {
            throw new AudioException('video_not_supported', 'This WebM file holds a video track. Upload the audio only.');
        }

        return $kind;
    }

    public static function head(string $path, int $bytes): string
    {
        $h = fopen($path, 'rb');
        if ($h === false) {
            throw new AudioException('source_copy_missing', 'The audio file cannot be opened.');
        }
        $data = fread($h, max(1, $bytes));
        fclose($h);

        return $data === false ? '' : $data;
    }

    /**
     * True when the movie box lists a video handler, false when it lists
     * only non-video handlers, null when the track list cannot be read
     * (case 14: then the file is treated as audio).
     */
    public static function mp4HasVideo(string $path, int $maxMoovBytes = 24_000_000): ?bool
    {
        $h = fopen($path, 'rb');
        if ($h === false) {
            return null;
        }
        $size = filesize($path);
        $pos = 0;
        $result = null;
        while ($size !== false && $pos + 8 <= $size) {
            fseek($h, $pos);
            $hdr = fread($h, 8);
            if ($hdr === false || strlen($hdr) < 8) {
                break;
            }
            $boxSize = unpack('N', substr($hdr, 0, 4))[1] ?? 0;
            $type = substr($hdr, 4, 4);
            $headerLen = 8;
            if ($boxSize === 1) {
                $ext = fread($h, 8);
                if ($ext === false || strlen($ext) < 8) {
                    break;
                }
                $parts = unpack('N2', $ext);
                $boxSize = (int) (($parts[1] ?? 0) * 4294967296 + ($parts[2] ?? 0));
                $headerLen = 16;
            } elseif ($boxSize === 0) {
                $boxSize = $size - $pos;
            }
            if ($boxSize < $headerLen) {
                break;
            }
            if ($type === 'moov') {
                if ($boxSize > $maxMoovBytes) {
                    break;
                }
                $moov = fread($h, max(1, (int) ($boxSize - $headerLen)));
                if ($moov === false) {
                    break;
                }
                $offset = 0;
                $handlers = [];
                while (($p = strpos($moov, 'hdlr', $offset)) !== false) {
                    $handlers[] = substr($moov, $p + 12, 4);
                    $offset = $p + 4;
                }
                if ($handlers !== []) {
                    $result = in_array('vide', $handlers, true);
                }
                break;
            }
            $pos += $boxSize;
        }
        fclose($h);

        return $result;
    }

    /**
     * Looks for a video codec id ("V_...") in the track entries before the
     * first cluster. Null when the header cannot be read.
     */
    public static function webmHasVideo(string $path): ?bool
    {
        $head = self::head($path, 4 * 1024 * 1024);
        $cluster = strpos($head, "\x1F\x43\xB6\x75");
        $tracks = $cluster === false ? $head : substr($head, 0, $cluster);
        if (!str_contains($tracks, "\x16\x54\xAE\x6B")) {
            return null;
        }
        if (preg_match('/\x86[\x80-\xFF]V_/', $tracks) === 1) {
            return true;
        }

        return preg_match('/\x86[\x80-\xFF]A_/', $tracks) === 1 ? false : null;
    }
}
