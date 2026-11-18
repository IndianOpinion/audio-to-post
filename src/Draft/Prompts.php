<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\Draft;

/**
 * The bundled prompts. They are generic: they say what the draft must
 * contain and the rules of case 63, and nothing about any publication's
 * style. Every prompt can be replaced on the settings screen or by the
 * `audiopost_prompt` filter.
 */
final class Prompts
{
    public const VARIABLES = ['title', 'source_label', 'duration', 'language', 'speakers', 'transcript', 'notes', 'mode'];

    private const RULES = <<<'TXT'
Rules:
- Write only from the transcript below. Do not add facts, names or numbers that are not in it.
- Each transcript line starts with a segment id in square brackets, then the speaker label.
- Refer to quotes and topic starts by segment id only. Never write a time or a timestamp.
- Every quote must be words copied exactly from the segment you cite.
- Attribute every quote to the speaker label shown in the transcript. Do not guess who a speaker is.
- If the transcript makes a speaker's name clear, you may suggest it in speaker_names with the segment that shows it.
- Plain text only: no HTML, no Markdown, no links.
- Answer with one JSON object in the requested shape and nothing else.
TXT;

    /** @return array<string, string> keys "<mode>.<pass>" */
    public static function defaults(): array
    {
        $head = "Recording: {title}\nSource: {source_label}\nDuration: {duration}\nLanguage: {language}\nSpeakers: {speakers}\n\n";

        return [
            'article.single' => "You are helping an editor turn a recorded interview, panel or briefing into a draft news article.\n"
                . "Write a clear, neutral article with a title, a short excerpt, sections of paragraphs (each paragraph lists the segment ids it draws on) and the strongest verbatim quotes.\n\n"
                . self::RULES . "\n\n" . $head . "Transcript:\n{transcript}",
            'show_notes.single' => "You are helping an editor write show notes for a recorded episode.\n"
                . "Give a title, a one-paragraph summary as the excerpt and as the first section, a list of topics with the segment id where each starts, and a few key verbatim quotes.\n\n"
                . self::RULES . "\n\n" . $head . "Transcript:\n{transcript}",
            'article.notes' => "You are reading one part of a long recording to prepare notes for a draft article.\n"
                . "Return the key points of this part, the segment ids of the best candidate quotes, and the topics that start in this part with their segment ids.\n"
                . "Notes from earlier parts are given for context; do not repeat them.\n\n"
                . self::RULES . "\n\n" . $head . "Notes so far:\n{notes}\n\nTranscript part:\n{transcript}",
            'show_notes.notes' => "You are reading one part of a long recording to prepare show notes.\n"
                . "Return the key points of this part, the segment ids of the best candidate quotes, and the topics that start in this part with their segment ids.\n"
                . "Notes from earlier parts are given for context; do not repeat them.\n\n"
                . self::RULES . "\n\n" . $head . "Notes so far:\n{notes}\n\nTranscript part:\n{transcript}",
            'article.final' => "You are helping an editor turn a long recording into a draft news article, working from notes on each part and the candidate quote segments in full.\n"
                . "Write a clear, neutral article with a title, a short excerpt, sections of paragraphs (each paragraph lists the segment ids it draws on) and the strongest verbatim quotes.\n\n"
                . self::RULES . "\n\n" . $head . "Notes:\n{notes}\n\nCandidate quote segments:\n{transcript}",
            'show_notes.final' => "You are helping an editor write show notes for a long recorded episode, working from notes on each part and the candidate quote segments in full.\n"
                . "Give a title, a one-paragraph summary as the excerpt and as the first section, a list of topics with the segment id where each starts, and a few key verbatim quotes.\n\n"
                . self::RULES . "\n\n" . $head . "Notes:\n{notes}\n\nCandidate quote segments:\n{transcript}",
        ];
    }

    /**
     * Replace known variables; unknown `{names}` are left as typed and
     * reported so the caller can log them once.
     *
     * @param array<string, string> $vars
     *
     * @return array{text: string, unknown: list<string>}
     */
    public static function render(string $template, array $vars): array
    {
        $unknown = [];
        $text = (string) preg_replace_callback('/\{([a-z_]+)\}/', static function (array $m) use ($vars, &$unknown): string {
            if (array_key_exists($m[1], $vars)) {
                return $vars[$m[1]];
            }
            $unknown[] = $m[1];

            return $m[0];
        }, $template);

        return ['text' => $text, 'unknown' => array_values(array_unique($unknown))];
    }

    /** Length of a prompt without its transcript and notes, for the estimate. */
    public static function overheadChars(string $template): int
    {
        return mb_strlen(str_replace(['{transcript}', '{notes}'], '', $template)) + 200;
    }
}
