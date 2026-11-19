<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Audio;

use IndianOpinion\AudioToPost\AudioException;

/**
 * Reads the RIFF/WAVE header: the `fmt ` chunk and where the `data`
 * chunk starts and ends.
 */
final class WavReader
{
    /**
     * @return array{audio_format: int, channels: int, sample_rate: int, byte_rate: int, block_align: int, bits: int, data_offset: int, data_size: int, pcm: bool}
     *
     * @throws AudioException
     */
    public static function read(string $path): array
    {
        $size = filesize($path);
        $h = fopen($path, 'rb');
        if ($h === false || $size === false) {
            throw new AudioException('source_copy_missing', 'The audio file cannot be opened.');
        }
        $riff = (string) fread($h, 12);
        if (strlen($riff) < 12 || !str_starts_with($riff, 'RIFF') || substr($riff, 8, 4) !== 'WAVE') {
            fclose($h);
            throw new AudioException('unreadable_audio', 'Not a RIFF/WAVE file.');
        }
        $fmt = null;
        $pos = 12;
        while ($pos + 8 <= $size) {
            fseek($h, $pos);
            $hdr = (string) fread($h, 8);
            if (strlen($hdr) < 8) {
                break;
            }
            $id = substr($hdr, 0, 4);
            $len = (int) (unpack('V', substr($hdr, 4, 4))[1] ?? 0);
            if ($id === 'fmt ') {
                $body = (string) fread($h, max(1, min($len, 64)));
                if (strlen($body) < 16) {
                    break;
                }
                $f = unpack('vformat/vchannels/Vrate/Vbyterate/valign/vbits', $body);
                if ($f === false) {
                    break;
                }
                if ((int) $f['format'] === 0xFFFE) {
                    // WAVE_FORMAT_EXTENSIBLE: the real format is the first
                    // two bytes of the sub-format GUID at offset 24.
                    $sub = strlen($body) >= 26 ? unpack('v', substr($body, 24, 2)) : false;
                    $f['sub'] = $sub === false ? 0 : (int) $sub[1];
                }
                $fmt = $f;
            } elseif ($id === 'data') {
                fclose($h);
                if ($fmt === null) {
                    throw new AudioException('unreadable_audio', 'The WAV data chunk comes before its format chunk.');
                }
                $dataOffset = $pos + 8;
                $dataSize = $len;
                if ($dataSize === 0 || $dataSize === 0xFFFFFFFF || $dataOffset + $dataSize > $size) {
                    $dataSize = $size - $dataOffset;
                }
                $channels = (int) $fmt['channels'];
                $rate = (int) $fmt['rate'];
                $bits = (int) $fmt['bits'];
                $align = (int) $fmt['align'];
                $byteRate = (int) $fmt['byterate'];
                if ($channels < 1 || $channels > 32 || $rate < 1 || $rate > 768000 || $bits < 1 || $bits > 64 || $align < 1) {
                    throw new AudioException('unreadable_audio', 'The WAV format chunk is not valid.');
                }
                $format = (int) $fmt['format'];
                // Plain integer PCM only, with a header that agrees with
                // itself; anything else is not split and its length is
                // left to the provider.
                $consistent = $align === $channels * intdiv($bits + 7, 8) && $byteRate === $rate * $align;
                $pcm = $consistent && ($format === 1 || ($format === 0xFFFE && (int) ($fmt['sub'] ?? 0) === 1));
                $dataSize -= $dataSize % $align;

                return [
                    'audio_format' => $format,
                    'channels' => $channels,
                    'sample_rate' => $rate,
                    'byte_rate' => $consistent ? $byteRate : 0,
                    'block_align' => $align,
                    'bits' => $bits,
                    'data_offset' => $dataOffset,
                    'data_size' => $dataSize,
                    'pcm' => $pcm,
                ];
            }
            $pos += 8 + $len + ($len % 2);
        }
        fclose($h);
        throw new AudioException('unreadable_audio', 'The WAV file has no data chunk.');
    }

    /**
     * A 44-byte canonical PCM header for a data block of the given size.
     */
    public static function header(int $channels, int $sampleRate, int $bits, int $dataSize, int $format = 1): string
    {
        $align = max(1, $channels * intdiv($bits + 7, 8));

        return 'RIFF' . pack('V', 36 + $dataSize) . 'WAVE'
            . 'fmt ' . pack('VvvVVvv', 16, $format === 0xFFFE ? 1 : $format, $channels, $sampleRate, $sampleRate * $align, $align, $bits)
            . 'data' . pack('V', $dataSize);
    }
}
