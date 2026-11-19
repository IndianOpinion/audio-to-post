<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Audio;

use IndianOpinion\AudioToPost\AudioException;

/**
 * Reads MPEG audio Layer III frame headers (MPEG-1, 2 and 2.5).
 *
 * It never decodes audio. It walks frame headers to count frames for an
 * exact duration (case 18) and to find frame-aligned byte offsets for
 * chunking (case 22). Reads are streamed in 1 MB blocks.
 */
final class Mp3Reader
{
    private const BITRATES_V1 = [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320];
    private const BITRATES_V2 = [0, 8, 16, 24, 32, 40, 48, 56, 64, 80, 96, 112, 128, 144, 160];
    private const RATES = [
        3 => [44100, 48000, 32000],
        2 => [22050, 24000, 16000],
        0 => [11025, 12000, 8000],
    ];
    private const BLOCK = 1048576;

    /**
     * Parse a 4-byte frame header. Returns null when it is not a valid
     * Layer III header.
     *
     * @return array{version: int, bitrate: int, sample_rate: int, padding: int, channels: int, length: int, samples: int}|null
     */
    public static function parseHeader(string $bytes): ?array
    {
        if (strlen($bytes) < 4) {
            return null;
        }
        $b = unpack('C4', $bytes);
        if ($b === false) {
            return null;
        }
        [$b0, $b1, $b2, $b3] = [$b[1], $b[2], $b[3], $b[4]];
        if ($b0 !== 0xFF || ($b1 & 0xE0) !== 0xE0) {
            return null;
        }
        $version = ($b1 >> 3) & 0x03;
        $layer = ($b1 >> 1) & 0x03;
        if ($version === 1 || $layer !== 1) {
            return null;
        }
        $bitrateIndex = ($b2 >> 4) & 0x0F;
        $rateIndex = ($b2 >> 2) & 0x03;
        if ($bitrateIndex === 0 || $bitrateIndex === 15 || $rateIndex === 3) {
            return null;
        }
        $padding = ($b2 >> 1) & 0x01;
        $mode = ($b3 >> 6) & 0x03;
        $bitrate = ($version === 3 ? self::BITRATES_V1 : self::BITRATES_V2)[$bitrateIndex] * 1000;
        $rate = self::RATES[$version][$rateIndex];
        $samples = $version === 3 ? 1152 : 576;
        $length = intdiv(($version === 3 ? 144 : 72) * $bitrate, $rate) + $padding;

        return [
            'version' => $version,
            'bitrate' => $bitrate,
            'sample_rate' => $rate,
            'padding' => $padding,
            'channels' => $mode === 3 ? 1 : 2,
            'length' => $length,
            'samples' => $samples,
        ];
    }

    /**
     * Size of an ID3v2 tag at the start of the file, or 0.
     */
    public static function id3v2Size(string $head): int
    {
        if (strlen($head) < 10 || !str_starts_with($head, 'ID3')) {
            return 0;
        }
        $s = unpack('C4', substr($head, 6, 4));
        if ($s === false || (($s[1] | $s[2] | $s[3] | $s[4]) & 0x80) !== 0) {
            // Not a syncsafe size: a broken or hostile tag. Scan from the start.
            return 0;
        }
        $size = ($s[1] << 21) | ($s[2] << 14) | ($s[3] << 7) | $s[4];
        $footer = (ord($head[5]) & 0x10) !== 0 ? 10 : 0;

        return 10 + $size + $footer;
    }

    /**
     * Walk the file's frames.
     *
     * Returns the exact frame count and duration unless the CPU budget
     * runs out, in which case `complete` is false and the figures cover
     * only the frames seen. For every boundary in `$boundariesMs` it
     * returns the byte offset and start time of the first frame that
     * starts at or after it.
     *
     * @param list<int> $boundariesMs ascending
     *
     * @return array{
     *   frames: int, duration_ms: int, complete: bool, first_offset: int, data_end: int,
     *   sample_rate: int, channels: int, version: int, variable: bool, bitrate: int,
     *   info_frames: ?int, info_offset: ?int,
     *   boundaries: list<array{offset: int, ms: int}>
     * }
     *
     * @throws AudioException when no frame can be found
     */
    public static function scan(string $path, array $boundariesMs = [], float $cpuSeconds = 10.0): array
    {
        $size = filesize($path);
        $h = fopen($path, 'rb');
        if ($h === false || $size === false) {
            throw new AudioException('source_copy_missing', 'The audio file cannot be opened.');
        }
        $head = (string) fread($h, 65536);
        $start = self::id3v2Size($head);
        $dataEnd = $size;
        if ($size >= 128) {
            fseek($h, $size - 128);
            if (fread($h, 3) === 'TAG') {
                $dataEnd = $size - 128;
            }
        }
        // Finding the first frame gets at least one second of its own, so a
        // file of junk bytes cannot hold the worker for minutes either way.
        $first = self::findFirstFrame($h, $start, $dataEnd, null, microtime(true) + max(1.0, $cpuSeconds));
        if ($first === null) {
            fclose($h);
            throw new AudioException('unreadable_audio', 'No MPEG audio frame was found in the file.');
        }
        [$offset, $hdr] = $first;
        $info = self::infoHeader($h, $offset, $hdr);

        $deadline = microtime(true) + $cpuSeconds;
        $frames = 0;
        $samples = 0;
        $bitrates = [];
        $boundaries = [];
        $bi = 0;
        $bcount = count($boundariesMs);
        $pos = $offset;
        $buf = '';
        $bufStart = $pos;
        $complete = true;
        $sampleRate = $hdr['sample_rate'];
        $isInfoFrame = $info !== null;
        while ($pos + 4 <= $dataEnd) {
            if ($pos + 4 > $bufStart + strlen($buf)) {
                fseek($h, $pos);
                $buf = (string) fread($h, self::BLOCK);
                $bufStart = $pos;
                if (strlen($buf) < 4) {
                    break;
                }
                if (microtime(true) > $deadline) {
                    $complete = false;
                    break;
                }
            }
            $f = self::parseHeader(substr($buf, $pos - $bufStart, 4));
            if ($f === null || $f['sample_rate'] !== $sampleRate) {
                // Lost sync: look for the next valid frame (junk or a tag inside the data).
                $next = self::findFirstFrame($h, $pos + 1, $dataEnd, $sampleRate, $deadline);
                if ($next === null) {
                    if (microtime(true) > $deadline) {
                        $complete = false;
                    }
                    break;
                }
                $pos = $next[0];
                $buf = '';
                $bufStart = $pos;
                continue;
            }
            if ($pos + $f['length'] > $dataEnd) {
                break;
            }
            if ($isInfoFrame) {
                // The Info/Xing frame holds no audio.
                $isInfoFrame = false;
                $pos += $f['length'];
                continue;
            }
            $ms = intdiv($samples * 1000, $sampleRate);
            while ($bi < $bcount && $boundariesMs[$bi] <= $ms) {
                $boundaries[] = ['offset' => $pos, 'ms' => $ms];
                $bi++;
            }
            $frames++;
            $samples += $f['samples'];
            $bitrates[$f['bitrate']] = true;
            $pos += $f['length'];
        }
        fclose($h);
        $durationMs = intdiv($samples * 1000, $sampleRate);
        while ($bi < $bcount) {
            $boundaries[] = ['offset' => $pos, 'ms' => $durationMs];
            $bi++;
        }

        return [
            'frames' => $frames,
            'duration_ms' => $durationMs,
            'complete' => $complete,
            'first_offset' => $offset,
            'data_end' => $complete ? $pos : $dataEnd,
            'sample_rate' => $sampleRate,
            'channels' => $hdr['channels'],
            'version' => $hdr['version'],
            'variable' => count($bitrates) > 1,
            'bitrate' => $hdr['bitrate'],
            'info_frames' => $info,
            'info_offset' => $info !== null ? $offset : null,
            'boundaries' => $boundaries,
        ];
    }

    /**
     * Find a frame header that is followed by a second valid header.
     *
     * @param resource $h
     *
     * @return array{0: int, 1: array{version: int, bitrate: int, sample_rate: int, padding: int, channels: int, length: int, samples: int}}|null
     */
    private static function findFirstFrame($h, int $from, int $end, ?int $sampleRate = null, ?float $deadline = null): ?array
    {
        $pos = $from;
        while ($pos + 4 <= $end) {
            if ($deadline !== null && microtime(true) > $deadline) {
                // A file of junk bytes must not hold the worker past its budget.
                return null;
            }
            fseek($h, $pos);
            $buf = (string) fread($h, self::BLOCK);
            if ($buf === '') {
                return null;
            }
            $len = strlen($buf);
            for ($i = 0; $i + 4 <= $len; $i++) {
                if ($buf[$i] !== "\xFF") {
                    continue;
                }
                $hdr = self::parseHeader(substr($buf, $i, 4));
                if ($hdr === null || ($sampleRate !== null && $hdr['sample_rate'] !== $sampleRate)) {
                    continue;
                }
                $at = $pos + $i;
                if ($at + $hdr['length'] + 4 > $end) {
                    // Last frame in the file: accept without a follower.
                    return $at + $hdr['length'] <= $end ? [$at, $hdr] : null;
                }
                fseek($h, $at + $hdr['length']);
                $next = self::parseHeader((string) fread($h, 4));
                if ($next !== null && $next['sample_rate'] === $hdr['sample_rate']) {
                    return [$at, $hdr];
                }
            }
            $pos += max(1, $len - 3);
        }

        return null;
    }

    /**
     * Frame count from a Xing, Info or VBRI header in the first frame, or
     * null when there is none.
     *
     * @param resource $h
     * @param array{version: int, channels: int, length: int} $hdr
     */
    private static function infoHeader($h, int $offset, array $hdr): ?int
    {
        fseek($h, $offset);
        $frame = (string) fread($h, max(4, min($hdr['length'], 2048)));
        $side = $hdr['version'] === 3 ? ($hdr['channels'] === 1 ? 17 : 32) : ($hdr['channels'] === 1 ? 9 : 17);
        $tag = substr($frame, 4 + $side, 4);
        if ($tag === 'Xing' || $tag === 'Info') {
            $flags = self::u32($frame, 8 + $side);
            if ($flags !== null && ($flags & 1) === 1) {
                return self::u32($frame, 12 + $side) ?? 0;
            }

            return 0;
        }
        if (substr($frame, 36, 4) === 'VBRI') {
            return self::u32($frame, 36 + 14) ?? 0;
        }

        return null;
    }

    /** Big-endian 32-bit integer at $at, or null when the bytes are not there. */
    private static function u32(string $bytes, int $at): ?int
    {
        if (strlen($bytes) < $at + 4) {
            return null;
        }
        $v = unpack('N', substr($bytes, $at, 4));

        return $v === false ? null : (int) $v[1];
    }
}
