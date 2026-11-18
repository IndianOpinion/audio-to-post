<?php

declare(strict_types=1);

namespace IndianOpinion\AudioToPost\WordPress;

/**
 * The usage ledger: one row per paid call in a small table (ruling on
 * open question 7), summed per local day for the caps. Each call costs
 * one insert; each cap check one SUM query.
 */
final class UsageLedger
{
    public const DB_VERSION = '1';
    public const KEEP_DAYS = 400;

    public function __construct(private readonly Clock $clock)
    {
    }

    public static function table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'audiopost_usage';
    }

    public static function install(): void
    {
        global $wpdb;
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  run_id bigint(20) unsigned NOT NULL DEFAULT 0,
  kind varchar(20) NOT NULL DEFAULT '',
  provider varchar(100) NOT NULL DEFAULT '',
  part varchar(40) NOT NULL DEFAULT '',
  billed_seconds int(11) NOT NULL DEFAULT 0,
  tokens_in int(11) NOT NULL DEFAULT 0,
  tokens_out int(11) NOT NULL DEFAULT 0,
  money_micros bigint(20) NULL,
  currency varchar(12) NOT NULL DEFAULT '',
  local_day date NOT NULL,
  created_utc datetime NOT NULL,
  http_status smallint(6) NULL,
  attempt smallint(6) NOT NULL DEFAULT 1,
  PRIMARY KEY  (id),
  KEY local_day (local_day),
  KEY run_id (run_id)
) {$charset};";
        if (!function_exists('dbDelta')) {
            $upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
            if (is_file($upgrade)) {
                require_once $upgrade;
            }
        }
        if (function_exists('dbDelta')) {
            dbDelta($sql);
        }
        update_option('audiopost_db_version', self::DB_VERSION, false);
    }

    public static function maybeInstall(): void
    {
        if (get_option('audiopost_db_version') !== self::DB_VERSION) {
            self::install();
        }
    }

    /**
     * Store one usage record (section 6.4) and fire the action.
     *
     * @param array{run_id: int, kind: string, provider: string, part: string, billed_seconds: int, tokens_in: int, tokens_out: int, money_micros: ?int, currency: string, http_status: ?int, attempt: int} $r
     *
     * @return array<string, mixed> the stored record
     */
    public function record(array $r): array
    {
        global $wpdb;
        $now = $this->clock->now();
        $row = $r + ['local_day' => $this->clock->localDay($now), 'created_utc' => gmdate('Y-m-d H:i:s', $now)];
        $wpdb->insert(self::table(), $row);
        $record = $row;
        $record['money'] = $r['money_micros'] === null ? null : \IndianOpinion\AudioToPost\Estimate\Money::format($r['money_micros'], 6);
        do_action('audiopost_usage_recorded', $record);

        return $record;
    }

    /**
     * Totals for a local day.
     *
     * @return array{seconds: int, tokens_in: int, tokens_out: int, money_micros: int, has_money: bool}
     */
    public function totals(?string $day = null): array
    {
        global $wpdb;
        $day ??= $this->clock->localDay();
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT SUM(billed_seconds) AS seconds, SUM(tokens_in) AS tokens_in, SUM(tokens_out) AS tokens_out, SUM(money_micros) AS money_micros, COUNT(money_micros) AS money_rows FROM ' . self::table() . ' WHERE local_day = %s',
            $day
        ), ARRAY_A);
        $row = is_array($row) ? $row : [];

        return [
            'seconds' => (int) ($row['seconds'] ?? 0),
            'tokens_in' => (int) ($row['tokens_in'] ?? 0),
            'tokens_out' => (int) ($row['tokens_out'] ?? 0),
            'money_micros' => (int) ($row['money_micros'] ?? 0),
            'has_money' => (int) ($row['money_rows'] ?? 0) > 0,
        ];
    }

    /** Delete rows older than KEEP_DAYS local days. */
    public function purge(): void
    {
        global $wpdb;
        $cutoff = $this->clock->localDay($this->clock->now() - self::KEEP_DAYS * 86400);
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE local_day < %s', $cutoff));
    }
}
