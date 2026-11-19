<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Tests\Support;

/**
 * Builds audio fixtures in the test: MPEG-1 Layer III frames with silent
 * payloads, and PCM WAV with a tone. No recorded audio is shipped.
 */
final class AudioFixtures
{
    private const BITRATE_INDEX = [32 => 1, 40 => 2, 48 => 3, 56 => 4, 64 => 5, 80 => 6, 96 => 7, 112 => 8, 128 => 9, 160 => 10, 192 => 11, 224 => 12, 256 => 13, 320 => 14];

    public static function dir(): string
    {
        $d = sys_get_temp_dir() . '/audiopost-fixtures-' . getmypid();
        if (!is_dir($d)) {
            mkdir($d, 0777, true);
        }

        return $d;
    }

    public static function frameHeader(int $kbps, bool $padding = false, bool $mono = false): string
    {
        $b2 = (self::BITRATE_INDEX[$kbps] << 4) | (0 << 2) | ($padding ? 2 : 0);
        $b3 = $mono ? 0xC0 : 0x00;

        return "\xFF\xFB" . chr($b2) . chr($b3);
    }

    public static function frameLength(int $kbps, bool $padding = false): int
    {
        return intdiv(144 * $kbps * 1000, 44100) + ($padding ? 1 : 0);
    }

    /**
     * An MP3 of about $seconds: MPEG-1 Layer III, 44.1 kHz. With $vbr the
     * bitrate changes frame by frame. Options add an ID3v2 tag, an
     * ID3v1 tag, or a Xing header frame.
     *
     * @param list<int> $vbr bitrates to cycle through
     *
     * @return array{path: string, frames: int, duration_ms: int}
     */
    public static function mp3(float $seconds, int $kbps = 128, array $vbr = [], bool $id3 = false, bool $xing = false, bool $id3v1 = false, ?string $path = null): array
    {
        $path ??= self::dir() . '/' . bin2hex(random_bytes(6)) . '.mp3';
        $frames = (int) ceil($seconds * 44100 / 1152);
        $h = fopen($path, 'wb');
        if ($id3) {
            $body = str_repeat("\0", 300);
            fwrite($h, 'ID3' . "\x03\x00\x00" . "\x00\x00\x02\x2C" . $body);
        }
        if ($xing) {
            $len = self::frameLength(128);
            $frame = self::frameHeader(128) . str_repeat("\0", 32) . 'Xing' . pack('N', 1) . pack('N', $frames);
            fwrite($h, str_pad($frame, $len, "\0"));
        }
        for ($i = 0; $i < $frames; $i++) {
            $rate = $vbr !== [] ? $vbr[$i % count($vbr)] : $kbps;
            $len = self::frameLength($rate);
            fwrite($h, self::frameHeader($rate) . str_repeat("\0", $len - 4));
        }
        if ($id3v1) {
            fwrite($h, 'TAG' . str_repeat("\0", 125));
        }
        fclose($h);

        return ['path' => $path, 'frames' => $frames, 'duration_ms' => intdiv($frames * 1152 * 1000, 44100)];
    }

    /**
     * A PCM WAV: 8 kHz, mono, 16-bit, a 440 Hz tone.
     */
    public static function wav(float $seconds, int $rate = 8000, ?string $path = null): string
    {
        $path ??= self::dir() . '/' . bin2hex(random_bytes(6)) . '.wav';
        $n = (int) round($seconds * $rate);
        $data = '';
        for ($i = 0; $i < $n; $i++) {
            $data .= pack('v', ((int) (3000 * sin(2 * M_PI * 440 * $i / $rate))) & 0xFFFF);
        }
        $header = 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
            . 'LIST' . pack('V', 4) . 'INFO'
            . 'data' . pack('V', strlen($data));
        file_put_contents($path, $header . $data);

        return $path;
    }

    /** A file that starts with the given bytes plus filler. */
    public static function bytes(string $head, int $size = 4096, string $ext = 'bin'): string
    {
        $path = self::dir() . '/' . bin2hex(random_bytes(6)) . '.' . $ext;
        file_put_contents($path, str_pad($head, $size, "\0"));

        return $path;
    }

    /**
     * An MP4 with a movie box listing the given handler types.
     *
     * @param list<string> $handlers e.g. ['soun'] or ['vide', 'soun']
     */
    public static function mp4(array $handlers): string
    {
        $box = static fn (string $type, string $body): string => pack('N', 8 + strlen($body)) . $type . $body;
        $traks = '';
        foreach ($handlers as $hdl) {
            $traks .= $box('trak', $box('mdia', $box('hdlr', "\0\0\0\0" . "\0\0\0\0" . $hdl . str_repeat("\0", 12) . "handler\0")));
        }
        $ftyp = $box('ftyp', 'M4A ' . "\0\0\0\0" . 'M4A isom');
        $path = self::dir() . '/' . bin2hex(random_bytes(6)) . '.m4a';
        file_put_contents($path, $ftyp . $box('mdat', str_repeat("\0", 1000)) . $box('moov', $box('mvhd', str_repeat("\0", 100)) . $traks));

        return $path;
    }

    public static function cleanup(): void
    {
        $d = self::dir();
        foreach (glob($d . '/*') ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
    }
}
