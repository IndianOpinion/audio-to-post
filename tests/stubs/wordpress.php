<?php

/**
 * A small in-memory stand-in for the WordPress functions the plugin uses,
 * so PHPUnit runs without a WordPress install. Behaviour that matters to
 * the plugin is copied faithfully: update_post_meta() unslashes its value,
 * add_option() refuses an existing option, filters chain.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/audiopost-wp/');
}
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

final class WPStub
{
    /** @var array<int, WP_Post> */
    public static array $posts = [];
    /** @var array<int, array<string, list<string>>> */
    public static array $meta = [];
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var array<string, array<int, list<callable>>> */
    public static array $filters = [];
    /** @var list<array{0: string, 1: list<mixed>}> */
    public static array $fired = [];
    /** @var array<int, string> attachment files */
    public static array $files = [];
    public static int $nextId = 1;
    public static int $userId = 1;
    /** @var list<string> */
    public static array $caps = ['upload_files', 'edit_posts', 'manage_options'];
    /** @var array<string, int> */
    public static array $cron = [];
    /** @var (callable(string, string, array<string, mixed>): (array<string, mixed>|WP_Error))|null */
    public static $http = null;
    /** @var list<array{url: string, args: array<string, mixed>}> */
    public static array $requests = [];
    public static string $uploads = '';
    /** @var array<string, array<string, mixed>> */
    public static array $routes = [];
    public static string $timezone = 'UTC';
    /** @var (callable(string): ?array<string, mixed>)|null */
    public static $audioMeta = null;

    public static function reset(): void
    {
        self::$posts = [];
        self::$meta = [];
        self::$options = [];
        self::$filters = [];
        self::$fired = [];
        self::$files = [];
        self::$nextId = 1;
        self::$userId = 1;
        self::$caps = ['upload_files', 'edit_posts', 'manage_options'];
        self::$cron = [];
        self::$http = null;
        self::$requests = [];
        self::$routes = [];
        self::$timezone = 'UTC';
        self::$audioMeta = null;
        $GLOBALS['wpdb'] = new FakeWpdb();
    }

    /** @return list<list<mixed>> arguments of each firing of an action */
    public static function fired(string $hook): array
    {
        $out = [];
        foreach (self::$fired as [$h, $args]) {
            if ($h === $hook) {
                $out[] = $args;
            }
        }

        return $out;
    }
}

final class FakeWpdb
{
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public string $postmeta = 'wp_postmeta';
    public string $sitemeta = 'wp_sitemeta';
    /** @var list<array<string, mixed>> */
    public array $usage = [];
    /** @var list<string> */
    public array $queries = [];
    /** @var list<mixed> */
    public array $lastArgs = [];
    public bool $failInserts = false;

    public function get_charset_collate(): string
    {
        return '';
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int|false
    {
        if ($this->failInserts) {
            return false;
        }
        $this->usage[] = $data;

        return 1;
    }

    public function prepare(string $sql, mixed ...$args): string
    {
        $this->lastArgs = $args;
        foreach ($args as $a) {
            $sql = (string) preg_replace('/%s|%d/', "'" . addslashes((string) $a) . "'", $sql, 1);
        }

        return $sql;
    }

    /** @return array<string, mixed>|null */
    public function get_row(string $sql, string $output = 'OBJECT'): ?array
    {
        $this->queries[] = $sql;
        if (preg_match("/local_day = '([0-9-]+)'/", $sql, $m) !== 1) {
            return null;
        }
        $row = ['seconds' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'money_micros' => 0, 'money_rows' => 0];
        foreach ($this->usage as $u) {
            if (($u['local_day'] ?? '') === $m[1]) {
                $row['seconds'] += (int) $u['billed_seconds'];
                $row['tokens_in'] += (int) $u['tokens_in'];
                $row['tokens_out'] += (int) $u['tokens_out'];
                if ($u['money_micros'] !== null) {
                    $row['money_micros'] += (int) $u['money_micros'];
                    $row['money_rows']++;
                }
            }
        }

        return $row;
    }

    public function query(string $sql): int
    {
        $this->queries[] = $sql;
        if (str_starts_with($sql, 'UPDATE wp_options SET option_value')) {
            [$new, $name, $old] = array_map('strval', $this->lastArgs);
            if (!array_key_exists($name, WPStub::$options) || maybe_serialize(WPStub::$options[$name]) !== $old) {
                return 0;
            }
            WPStub::$options[$name] = unserialize($new);

            return 1;
        }
        if (preg_match("/DELETE FROM \S+usage WHERE local_day < '([0-9-]+)'/", $sql, $m) === 1) {
            $before = count($this->usage);
            $this->usage = array_values(array_filter($this->usage, static fn (array $u): bool => (string) $u['local_day'] >= $m[1]));

            return $before - count($this->usage);
        }
        if (str_starts_with($sql, 'DROP TABLE')) {
            $this->usage = [];
        }
        if (str_contains($sql, "option_name LIKE 'audiopost")) {
            foreach (array_keys(WPStub::$options) as $k) {
                if (str_starts_with($k, 'audiopost_lock_') || str_starts_with($k, 'audiopost_usage_')) {
                    unset(WPStub::$options[$k]);
                }
            }
        }

        return 0;
    }
}

class WP_Error
{
    /** @var array<string, list<string>> */
    public array $errors = [];
    /** @var array<string, mixed> */
    public array $error_data = [];

    public function __construct(string $code = '', string $message = '', mixed $data = '')
    {
        if ($code !== '') {
            $this->errors[$code] = [$message];
            if ($data !== '') {
                $this->error_data[$code] = $data;
            }
        }
    }

    public function get_error_code(): string
    {
        return (string) (array_key_first($this->errors) ?? '');
    }

    public function get_error_message(): string
    {
        $c = $this->get_error_code();

        return $this->errors[$c][0] ?? '';
    }

    public function get_error_data(string $code = ''): mixed
    {
        return $this->error_data[$code !== '' ? $code : $this->get_error_code()] ?? null;
    }

    public function add_data(mixed $data, string $code = ''): void
    {
        $this->error_data[$code !== '' ? $code : $this->get_error_code()] = $data;
    }
}

class WP_Post
{
    public int $ID = 0;
    public string $post_type = 'post';
    public string $post_status = 'draft';
    public string $post_title = '';
    public string $post_content = '';
    public string $post_excerpt = '';
    public int|string $post_author = 0;
    public string $post_mime_type = '';
    public string $post_date = '';

    /** @param array<string, mixed> $a */
    public function __construct(array $a = [])
    {
        foreach ($a as $k => $v) {
            if (property_exists($this, $k)) {
                $this->$k = $v;
            }
        }
    }
}

class WP_User
{
    public int $ID = 1;
    public string $user_login = 'editor';
    public string $user_email = 'editor@example.org';
}

/** @implements ArrayAccess<string, mixed> */
class WP_REST_Request implements ArrayAccess
{
    /** @var array<string, mixed> */
    private array $params;

    /** @param array<string, mixed> $params */
    public function __construct(string $method = 'GET', string $route = '', array $params = [])
    {
        $this->params = $params;
    }

    public function get_param(string $k): mixed
    {
        return $this->params[$k] ?? null;
    }

    public function set_param(string $k, mixed $v): void
    {
        $this->params[$k] = $v;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->params[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->params[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->params[(string) $offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->params[$offset]);
    }
}

class WP_REST_Response
{
    public function __construct(public mixed $data = null, public int $status = 200)
    {
    }

    public function get_data(): mixed
    {
        return $this->data;
    }

    public function get_status(): int
    {
        return $this->status;
    }
}

final class CliExit extends RuntimeException
{
}

final class WP_CLI
{
    /** @var list<string> */
    public static array $out = [];

    public static function log(string $m): void
    {
        self::$out[] = $m;
    }

    public static function line(string $m = ''): void
    {
        self::$out[] = $m;
    }

    public static function success(string $m): void
    {
        self::$out[] = 'Success: ' . $m;
    }

    public static function warning(string $m): void
    {
        self::$out[] = 'Warning: ' . $m;
    }

    public static function error(string $m): void
    {
        self::$out[] = 'Error: ' . $m;
        throw new CliExit($m, 1);
    }

    public static function halt(int $code): void
    {
        throw new CliExit('halt', $code);
    }

    public static function add_command(string $name, mixed $callable): void
    {
    }
}

// Hooks.
function add_filter(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
{
    WPStub::$filters[$hook][$priority][] = $cb;

    return true;
}
function add_action(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $cb, $priority, $args);
}
function remove_action(string $hook, callable $cb, int $priority = 10): bool
{
    foreach (WPStub::$filters[$hook][$priority] ?? [] as $i => $f) {
        if ($f === $cb) {
            unset(WPStub::$filters[$hook][$priority][$i]);

            return true;
        }
    }

    return false;
}
function remove_all_filters(string $hook): void
{
    unset(WPStub::$filters[$hook]);
}
function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    $list = WPStub::$filters[$hook] ?? [];
    ksort($list);
    foreach ($list as $cbs) {
        foreach ($cbs as $cb) {
            $value = $cb($value, ...$args);
        }
    }

    return $value;
}
function do_action(string $hook, mixed ...$args): void
{
    WPStub::$fired[] = [$hook, $args];
    $list = WPStub::$filters[$hook] ?? [];
    ksort($list);
    foreach ($list as $cbs) {
        foreach ($cbs as $cb) {
            $cb(...$args);
        }
    }
}
function did_action(string $hook): int
{
    return count(WPStub::fired($hook));
}

// Options.
function get_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, WPStub::$options) ? WPStub::$options[$name] : $default;
}
function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = null): bool
{
    if (array_key_exists($name, WPStub::$options)) {
        return false;
    }
    WPStub::$options[$name] = $value;

    return true;
}
function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    WPStub::$options[$name] = $value;

    return true;
}
function delete_option(string $name): bool
{
    unset(WPStub::$options[$name]);

    return true;
}
function maybe_serialize(mixed $data): mixed
{
    return is_array($data) || is_object($data) ? serialize($data) : $data;
}
function wp_cache_delete(int|string $key, string $group = ''): bool
{
    return true;
}

// Slashes.
function wp_slash(mixed $v): mixed
{
    if (is_array($v)) {
        return array_map('wp_slash', $v);
    }

    return is_string($v) ? addslashes($v) : $v;
}
function wp_unslash(mixed $v): mixed
{
    if (is_array($v)) {
        return array_map('wp_unslash', $v);
    }

    return is_string($v) ? stripslashes($v) : $v;
}

// Posts.
function register_post_type(string $type, array $args = []): void
{
}
/** @param array<string, mixed> $arr */
function wp_insert_post(array $arr, bool $wpError = false): int|WP_Error
{
    $arr = wp_unslash($arr);
    $id = WPStub::$nextId++;
    $arr['ID'] = $id;
    $arr['post_author'] = (int) ($arr['post_author'] ?? WPStub::$userId);
    WPStub::$posts[$id] = new WP_Post($arr);

    return $id;
}
function wp_insert_attachment(array $arr, string $file = ''): int
{
    $arr['post_type'] = 'attachment';
    $id = wp_insert_post($arr);
    WPStub::$files[(int) $id] = $file;

    return (int) $id;
}
function get_post(int|WP_Post|null $id = null): ?WP_Post
{
    if ($id instanceof WP_Post) {
        return $id;
    }

    return WPStub::$posts[(int) $id] ?? null;
}
function wp_delete_post(int $id, bool $force = false): bool
{
    do_action('before_delete_post', $id);
    unset(WPStub::$posts[$id], WPStub::$meta[$id]);

    return true;
}
function get_post_meta(int $id, string $key = '', bool $single = false): mixed
{
    $v = WPStub::$meta[$id][$key] ?? [];

    return $single ? ($v[0] ?? '') : $v;
}
function update_post_meta(int $id, string $key, mixed $value): bool
{
    WPStub::$meta[$id][$key] = [wp_unslash(is_int($value) ? (string) $value : $value)];

    return true;
}
function delete_post_meta(int $id, string $key): bool
{
    unset(WPStub::$meta[$id][$key]);

    return true;
}
function delete_post_meta_by_key(string $key): bool
{
    foreach (WPStub::$meta as $id => $m) {
        unset(WPStub::$meta[$id][$key]);
    }

    return true;
}
/** @param array<string, mixed> $args @return list<int|WP_Post> */
function get_posts(array $args = []): array
{
    $out = [];
    foreach (WPStub::$posts as $p) {
        if (isset($args['post_type']) && $p->post_type !== $args['post_type']) {
            continue;
        }
        if (isset($args['author']) && (int) $p->post_author !== (int) $args['author']) {
            continue;
        }
        if (isset($args['meta_query'][0])) {
            $q = $args['meta_query'][0];
            $v = WPStub::$meta[$p->ID][$q['key']][0] ?? null;
            if (!in_array($v, (array) $q['value'], true)) {
                continue;
            }
        }
        $out[] = $p;
    }
    usort($out, static fn (WP_Post $a, WP_Post $b): int => ($args['order'] ?? 'DESC') === 'ASC' ? $a->ID <=> $b->ID : $b->ID <=> $a->ID);
    $limit = (int) ($args['posts_per_page'] ?? $args['numberposts'] ?? 5);
    $out = array_slice($out, (int) ($args['offset'] ?? 0), $limit < 0 ? null : $limit);

    return ($args['fields'] ?? '') === 'ids' ? array_map(static fn (WP_Post $p): int => $p->ID, $out) : $out;
}
function get_post_mime_type(int $id): string|false
{
    return isset(WPStub::$posts[$id]) ? WPStub::$posts[$id]->post_mime_type : false;
}
function get_attached_file(int $id): string|false
{
    return WPStub::$files[$id] ?? false;
}
function wp_get_attachment_url(int $id): string|false
{
    return isset(WPStub::$files[$id]) ? 'https://example.org/wp-content/uploads/' . basename(WPStub::$files[$id]) : false;
}
function get_the_title(int $id = 0): string
{
    return WPStub::$posts[$id]->post_title ?? '';
}
function get_edit_post_link(int $id): string
{
    return 'https://example.org/wp-admin/post.php?post=' . $id . '&action=edit';
}
function is_wp_error(mixed $v): bool
{
    return $v instanceof WP_Error;
}

// Users.
function get_current_user_id(): int
{
    return WPStub::$userId;
}
function current_user_can(string $cap, mixed ...$args): bool
{
    return in_array($cap, WPStub::$caps, true);
}
function get_user_by(string $field, string $value): WP_User|false
{
    $u = new WP_User();
    $u->ID = WPStub::$userId;

    return $value === $u->user_email ? $u : false;
}
function wp_get_current_user(): WP_User
{
    return new WP_User();
}

// Time.
function wp_timezone(): DateTimeZone
{
    return new DateTimeZone(WPStub::$timezone);
}
function wp_date(string $format, ?int $ts = null): string
{
    return (new DateTimeImmutable('@' . ($ts ?? time())))->setTimezone(wp_timezone())->format($format);
}

// Cron.
function wp_next_scheduled(string $hook): int|false
{
    return WPStub::$cron[$hook] ?? false;
}
function wp_schedule_event(int $ts, string $recurrence, string $hook): bool
{
    WPStub::$cron[$hook] = $ts;

    return true;
}
function wp_clear_scheduled_hook(string $hook): int
{
    unset(WPStub::$cron[$hook]);

    return 1;
}

// Uploads.
/** @return array<string, string|false> */
function wp_upload_dir(?string $time = null, bool $create = true): array
{
    return ['basedir' => WPStub::$uploads, 'baseurl' => 'https://example.org/wp-content/uploads', 'error' => false];
}
function wp_mkdir_p(string $dir): bool
{
    // Core silences mkdir() the same way and reports failure as false.
    return is_dir($dir) || @mkdir($dir, 0777, true);
}
function wp_read_audio_metadata(string $file): array|false
{
    if (WPStub::$audioMeta !== null) {
        return (WPStub::$audioMeta)($file) ?? false;
    }

    return false;
}

// HTTP.
/** @param array<string, mixed> $args */
function wp_remote_request(string $url, array $args = []): array|WP_Error
{
    WPStub::$requests[] = ['url' => $url, 'args' => $args];
    if (WPStub::$http === null) {
        return new WP_Error('http_request_failed', 'No network in tests.');
    }

    return (WPStub::$http)($url, (string) ($args['method'] ?? 'GET'), $args);
}
/** @param array<string, mixed> $args */
function wp_safe_remote_request(string $url, array $args = []): array|WP_Error
{
    $args['reject_unsafe_urls'] = true;

    return wp_remote_request($url, $args);
}
/** @param array<string, mixed> $args */
function wp_remote_get(string $url, array $args = []): array|WP_Error
{
    return wp_remote_request($url, $args + ['method' => 'GET']);
}
function wp_remote_retrieve_response_code(mixed $r): int|string
{
    return is_array($r) ? (int) ($r['response']['code'] ?? 0) : '';
}
function wp_remote_retrieve_body(mixed $r): string
{
    return is_array($r) ? (string) ($r['body'] ?? '') : '';
}
/** @return array<string, string> */
function wp_remote_retrieve_headers(mixed $r): array
{
    return is_array($r) ? (array) ($r['headers'] ?? []) : [];
}

// Formatting and URLs.
function sanitize_text_field(string $s): string
{
    return trim((string) preg_replace('/[\r\n\t ]+/', ' ', strip_tags($s)));
}
function sanitize_key(string $s): string
{
    return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($s));
}
function esc_html(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function esc_attr(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function esc_url(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function esc_url_raw(string $s): string
{
    return $s;
}
function esc_textarea(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function esc_html__(string $s, string $d = ''): string
{
    return esc_html($s);
}
function wp_kses_post(string $s): string
{
    return $s;
}
function absint(mixed $v): int
{
    return abs((int) $v);
}
function home_url(string $path = ''): string
{
    return 'https://example.org' . $path;
}
function admin_url(string $path = ''): string
{
    return 'https://example.org/wp-admin/' . $path;
}
/** @param array<string, mixed> $args */
function add_query_arg(array $args, string $url): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
}
function is_admin(): bool
{
    return false;
}
function is_multisite(): bool
{
    return false;
}
function size_format(int|float $bytes): string
{
    return $bytes . ' B';
}
function wp_rand(int $min = 0, int $max = 0): int
{
    return random_int($min, $max > 0 ? $max : PHP_INT_MAX);
}
function dbDelta(string|array $sql): array
{
    return [];
}
/** @param array<string, mixed> $args */
function register_rest_route(string $ns, string $route, array $args): bool
{
    WPStub::$routes[$ns . $route] = $args;

    return true;
}
function wp_json_encode(mixed $v, int $flags = 0): string|false
{
    return json_encode($v, $flags);
}
function wp_add_privacy_policy_content(string $name, string $content): void
{
}
