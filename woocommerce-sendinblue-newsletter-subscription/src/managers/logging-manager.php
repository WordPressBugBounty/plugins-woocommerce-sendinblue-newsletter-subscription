<?php

namespace SendinblueWoocommerce\Managers;

/**
 * Class LoggingManager
 *
 * Owns the on-disk home for Brevo debug logs: where the directory lives, how it
 * is protected from public access, and how Brevo's backend reads back from it.
 *
 * This class owns the whole pipeline: the enable gate and its 24h expiry
 * (is_enabled() / enable() / disable()), the write path with its size and
 * retention limits, and the read path Brevo's backend consumes. The gate is
 * re-checked in here on every write, so instrumented call sites may call
 * is_enabled() to skip building context cheaply, but never need to for
 * correctness.
 *
 * Design notes worth keeping in mind before changing anything here:
 *
 * 1. The directory lives under wp_upload_dir(), NOT under the plugin directory.
 *    The plugin directory is wiped on every update; uploads survives.
 *
 * 2. Everything under uploads is served over HTTP by default. The .htaccess we
 *    write only protects Apache — nginx ignores it entirely. The unguessable
 *    per-site hash in the directory name is therefore the real access control,
 *    not defence-in-depth. Do not remove it. This is the same approach
 *    WooCommerce uses for its own wc-logs. File names carry a SEPARATE hash,
 *    derived one-way from the site hash (see get_file_hash()): the backend
 *    API returns file names, so anything embedded in them is disclosed — the
 *    directory's secret must never be.
 *
 * 3. Files are JSON Lines: one complete JSON object per line, newline
 *    terminated, never pretty-printed. That is what lets the backend resume a
 *    read from a byte offset with a single fseek instead of parsing from the
 *    top, and it is why read_file() only ever returns whole lines.
 *
 * 4. Record fields use the same vocabulary as the structured contact-sync logs
 *    in integration-events-consumers (date, info_message / error_message,
 *    isErr, userConnectionId, connector, shopHost, time_processing, data), so
 *    the people who read those logs can read these without a translation
 *    table, and the backend can join both sides on userConnectionId.
 *
 * 5. Logging must never take a storefront down. Every failure path here
 *    degrades to a silent no-op.
 *
 * @package SendinblueWoocommerce\Managers
 */
class LoggingManager
{
    /** Directory name prefix, followed by the per-site hash. */
    const DIR_PREFIX = 'brevo-logs-';

    /** Log file name prefix, followed by date and the derived file hash. */
    const FILE_PREFIX = 'brevo-';

    const FILE_EXTENSION = '.log';

    /** Length of the hashes used in the directory and file names. */
    const HASH_LENGTH = 32;

    /** Files older than this are pruned. The capture window is only 24h. */
    const RETENTION_DAYS = 3;

    /**
     * Ceiling on log growth, in bytes (20 MB). Enforced twice: append()
     * refuses to grow the current day's file past it, and prune() drops
     * oldest files at day rollover until the directory total is back under
     * it. Between rollovers the directory can therefore briefly hold up to
     * one ceiling of older days plus the capped current day.
     */
    const MAX_TOTAL_BYTES = 20971520;

    /** Longest single line we will append, in bytes (64 KB). */
    const MAX_LINE_BYTES = 65536;

    /** Default and maximum read window for a single backend fetch. */
    const READ_CHUNK_DEFAULT = 262144;
    const READ_CHUNK_MAX = 1048576;

    /**
     * Hard ceiling on how long logging may stay on, in seconds.
     *
     * Applied to whatever any caller asks for, including Brevo. A bug or a
     * tampered payload upstream cannot widen a merchant's window past this.
     */
    const MAX_WINDOW_SECONDS = 86400;

    /** Cron hook for the off-path retention sweep. */
    const CRON_HOOK = 'sendinblue_wc_logging_sweep';

    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARN = 'warn';
    const LEVEL_ERROR = 'error';

    /** Per-request memo so the enable check does not hit options repeatedly. */
    private $enabled = null;

    /**
     * Why the directory is unusable, when it is. Surfaced by get_status() so
     * "logging is off" and "logging is broken" are distinguishable.
     *
     * @var string|null
     */
    private $last_error = null;

    /**
     * Cached absolute directory path for this request, or false once we have
     * established that it is unusable. Null means "not resolved yet".
     *
     * @var string|false|null
     */
    private $directory = null;

    /**
     * Buffered per-request narrative, flushed as ONE summary record at
     * shutdown instead of one line per event. This is what keeps a contact
     * full sync at one line per pull request rather than hundreds.
     */
    private $request_notes = array();

    /** @var string[] Buffered per-request error narrative. */
    private $request_errors = array();

    /** @var int Notes dropped after the buffer cap, reported on flush. */
    private $notes_dropped = 0;

    /** @var bool Whether the shutdown flush has been registered. */
    private $flush_hooked = false;

    /** @var bool A log triggered by logging must not recurse. */
    private $reentrant = false;

    /** Hard cap on buffered notes so a runaway loop cannot eat memory. */
    const MAX_NOTES = 200;

    /**
     * Shared instance for the current request.
     *
     * Instrumentation is spread across every manager and fires many times per
     * request. Going through one instance keeps the enable check, the
     * directory resolve and the open file handle to one each, rather than one
     * per call site.
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * Correlation id shared by every record written during this PHP request.
     *
     * Once instrumentation is dense, timestamps alone cannot tell "one request
     * logged twice" from "two requests that look alike" — which is exactly the
     * question support ends up asking. This can.
     *
     * @var string|null
     */
    private static $request_id = null;

    /**
     * @return self
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
            // Zero footprint while logging is off: displacing the site-wide
            // error handler on every request of every shop is a bigger
            // surface than the feature needs. do_enable() installs it the
            // moment a window opens, so the enabling request captures too.
            if (self::$instance->is_enabled()) {
                self::$instance->install_error_handler();
            }
        }

        return self::$instance;
    }

    /** Guard against double registration across instance() calls. */
    private static $error_handler_installed = false;

    /**
     * Route the plugin's own warnings/notices/deprecations into the log.
     *
     * Scope filter is load-bearing: without the ROOT_PATH check every other
     * plugin's noise fills the 20 MB cap in minutes. This logs plugin-scoped
     * errors first, then delegates to whatever handler set_error_handler()
     * displaces (e.g. Sentry's) — or to PHP's own internal handler if there
     * was none — so installing ours never silently blinds another handler.
     *
     * @return void
     */
    public function install_error_handler()
    {
        if (self::$error_handler_installed) {
            return;
        }
        self::$error_handler_installed = true;

        // $previous is only known once set_error_handler() returns, which is
        // after the closure below is already constructed. It is captured by
        // reference (`use (&$previous)`) so that when the closure actually
        // runs later (on some subsequent error), it sees the value assigned
        // to $previous after registration, not the null it closed over.
        $previous = null;

        $previous = set_error_handler(function ($no, $str, $file = '', $line = 0) use (&$previous) {
            try {
                $root = rtrim(SENDINBLUE_WC_ROOT_PATH, '/\\') . DIRECTORY_SEPARATOR;
                $self = self::instance();
                if (is_string($file)
                    && strpos($file, $root) === 0
                    && $self->is_enabled()
                ) {
                    $self->log(self::LEVEL_WARN, 'php', $str, array(
                        'file'  => basename($file),
                        'line'  => (int) $line,
                        'errno' => (int) $no,
                    ));
                }
            } catch (\Throwable $e) {
                // no-op: an error handler must never itself throw.
            } catch (\Exception $e) {
                // PHP 5.6 has no \Throwable; this arm is dead on 7+.
            }

            // Delegate to the handler we displaced, if any, so it still runs.
            // Wrapped the same way: a throwing third-party handler must not
            // escape from inside ours.
            try {
                if ($previous) {
                    // A displaced handler returning null means "continue to
                    // default handling"; the (bool) cast maps null to false,
                    // which is exactly that semantics. Deliberate — do not
                    // "fix" the cast to preserve null.
                    return (bool) call_user_func_array($previous, func_get_args());
                }
            } catch (\Throwable $e) {
                return false;
            } catch (\Exception $e) {
                // PHP 5.6 has no \Throwable; this arm is dead on 7+.
                return false;
            }

            return false; // no previous handler: fall back to PHP's internal handler
        }, E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE | E_USER_DEPRECATED);
    }

    /**
     * Short id for the current request. Carries no information about the shop,
     * the visitor or the route — it only has to be distinct from its neighbours
     * in the same file.
     *
     * @return string
     */
    public static function request_id()
    {
        if (self::$request_id === null) {
            self::$request_id = function_exists('wp_generate_password')
                ? wp_generate_password(8, false, false)
                : substr(md5(uniqid('', true)), 0, 8);
        }

        return self::$request_id;
    }

    /**
     * Absolute path to the log directory, creating and protecting it if needed.
     *
     * @return string|null Trailing-slashed path, or null when unusable.
     */
    public function get_directory()
    {
        if ($this->directory !== null) {
            return $this->directory === false ? null : $this->directory;
        }

        $this->directory = false;
        $this->last_error = null;

        $uploads = wp_upload_dir();
        if (empty($uploads['basedir']) || !empty($uploads['error'])) {
            $this->last_error = 'uploads_unavailable';

            return null;
        }

        $path = trailingslashit($uploads['basedir']) . $this->get_directory_name();

        if (!is_dir($path) && !wp_mkdir_p($path)) {
            $this->last_error = 'mkdir_failed';

            return null;
        }

        // Whoever writes first creates this directory, and that is not always
        // the web server: WP-CLI and system cron frequently run as root or a
        // deploy user. A directory owned by the wrong user leaves the web
        // server unable to append, and because logging fails quietly by
        // design that looks exactly like logging being switched off.
        $this->align_ownership($path, $uploads['basedir']);

        if (!is_writable($path)) {
            $this->last_error = 'not_writable';

            return null;
        }

        $this->ensure_protected($path);

        $this->directory = trailingslashit($path);

        return $this->directory;
    }

    /**
     * Absolute path to the log directory only if it already exists on disk.
     *
     * The backend read side (list/fetch) goes through this instead of
     * get_directory(): a GET against a shop that has never logged, or whose
     * directory was pruned, must get null — never a freshly minted session
     * directory as a side effect.
     *
     * @return string|null Trailing-slashed path, or null.
     */
    public function get_existing_directory()
    {
        $stored = get_option(SENDINBLUE_WC_LOGS_DIR, null);
        if (!is_string($stored) || !$this->is_valid_directory_name($stored)) {
            return null;
        }

        $uploads = wp_upload_dir();
        if (empty($uploads['basedir']) || !empty($uploads['error'])) {
            return null;
        }

        $path = trailingslashit($uploads['basedir']) . $stored;

        return is_dir($path) ? trailingslashit($path) : null;
    }

    /**
     * Make the log directory match the uploads directory's owner, group and
     * permissions, so whichever process created it, the web server can write.
     *
     * A no-op unless the running process has the privilege to change them —
     * in practice this repairs a root-created directory the next time any
     * root context touches it, and does nothing when the web server runs.
     *
     * @param string $path      Log directory.
     * @param string $reference Uploads base directory.
     * @return void
     */
    private function align_ownership($path, $reference)
    {
        if (!function_exists('fileowner') || !function_exists('chown')) {
            return;
        }

        $owner = @fileowner($reference);
        $group = @filegroup($reference);

        if ($owner === false) {
            return;
        }

        $targets = array(rtrim($path, '/'));

        $entries = @glob(trailingslashit($path) . '*');
        if (is_array($entries)) {
            $targets = array_merge($targets, $entries);
        }

        // Guard files are dot-prefixed, so glob('*') does not see them.
        foreach (array('.htaccess') as $hidden) {
            $hidden_path = trailingslashit($path) . $hidden;
            if (file_exists($hidden_path)) {
                $targets[] = $hidden_path;
            }
        }

        foreach ($targets as $target) {
            if (@fileowner($target) !== $owner) {
                @chown($target, $owner);
            }

            if ($group !== false && @filegroup($target) !== $group) {
                @chgrp($target, $group);
            }
        }

        // Deliberately no chmod here. The failure this repairs is ownership,
        // and a root-created directory handed to the web server is writable
        // by it once owned, whatever the mode. Mirroring the mode as well
        // would silently undo an administrator who locked the directory on
        // purpose — get_status() reports that instead.
    }

    /**
     * Why logging is or is not currently producing output.
     *
     * Exists because a silent no-op is the right behaviour for a storefront
     * but a terrible one to debug: without this, "switched off", "directory
     * owned by the wrong user" and "uploads is read-only" are indistinguishable
     * from the outside. Intended for the admin notice and the fetch response.
     *
     * @return array
     */
    public function get_status()
    {
        return $this->safe(function () {
            return $this->do_get_status();
        }, array());
    }

    /**
     * @return array
     */
    private function do_get_status()
    {
        $enabled = $this->is_enabled();

        // Reads never create (see get_existing_directory()): status is asked
        // by the backend's list mode, and probing get_directory() here would
        // mint a session directory — option write, mkdir, ownership, guard
        // files — on any shop the backend lists, logging on or off. The cost
        // is that mkdir-class failures (write-path knowledge in last_error)
        // surface as 'missing' when an enabled session's directory is absent.
        $uploads = wp_upload_dir();
        $directory = $this->get_existing_directory();
        $writable = $directory !== null && is_writable($directory);

        $reason = null;
        if (empty($uploads['basedir']) || !empty($uploads['error'])) {
            $reason = 'uploads_unavailable';
        } elseif ($directory === null) {
            $reason = $enabled ? 'missing' : 'disabled';
        } elseif (!$writable) {
            $reason = 'not_writable';
        } elseif (!$enabled) {
            $reason = 'disabled';
        }

        return array(
            'enabled'   => $enabled,
            'writable'  => $writable,
            'directory' => $directory,
            'expires_at' => $enabled ? (int) get_option(SENDINBLUE_WC_LOGS_EXPIRES, 0) : 0,
            // list_files() is a wrapped public entry; do_get_status() always
            // runs inside an active safe() call, so the reentrancy guard
            // would turn the public form into a no-op here.
            'files'     => count($this->do_list_files()),
            'reason'    => $reason,
        );
    }

    /**
     * Directory name for the current logging session, without any path.
     *
     * Shape: brevo-logs-{Y-m-d}-{His}-{32-char hash}
     *
     * The timestamp is when the session started, so directories sort
     * chronologically and a support engineer can see at a glance which window
     * a set of logs belongs to. The hash is what keeps the path unguessable:
     * `.htaccess` protects Apache and nothing else, so on nginx this is the
     * only barrier between these files and the open internet. A timestamp
     * alone would be trivially enumerable — a day is 86,400 candidates.
     *
     * @return string
     */
    public function get_directory_name()
    {
        $stored = get_option(SENDINBLUE_WC_LOGS_DIR, null);

        if (is_string($stored) && $this->is_valid_directory_name($stored)) {
            return $stored;
        }

        return $this->start_new_directory();
    }

    /**
     * Begin a new session directory and remember its name.
     *
     * @return string
     */
    private function start_new_directory()
    {
        $name = self::DIR_PREFIX . gmdate('Y-m-d-His') . '-' . $this->get_site_hash();

        $this->set_option(SENDINBLUE_WC_LOGS_DIR, $name);
        $this->directory = null;

        return $name;
    }

    /**
     * @param string $name
     * @return bool
     */
    public function is_valid_directory_name($name)
    {
        if (!is_string($name) || $name === '' || $name !== basename($name)) {
            return false;
        }

        $pattern = '/^' . preg_quote(self::DIR_PREFIX, '/')
            . '\d{4}-\d{2}-\d{2}-\d{6}-[A-Za-z0-9]{' . self::HASH_LENGTH . '}$/';

        return (bool) preg_match($pattern, $name);
    }

    /**
     * Per-site random hash used in the directory name — and nowhere else.
     *
     * Generated once and stored. On multisite each blog gets its own value,
     * because get_option()/wp_upload_dir() are both per-blog.
     *
     * This value is the access control on nginx (design note 2), so it must
     * never appear in anything the backend API returns: not in file names,
     * not in any response field.
     *
     * @return string
     */
    public function get_site_hash()
    {
        $hash = get_option(SENDINBLUE_WC_LOGS_HASH, null);

        if (is_string($hash) && preg_match('/^[A-Za-z0-9]{' . self::HASH_LENGTH . '}$/', $hash)) {
            return $hash;
        }

        // wp_generate_password with special characters off returns [A-Za-z0-9],
        // which keeps the value safe in both a path and a URL.
        $hash = wp_generate_password(self::HASH_LENGTH, false, false);

        if (get_option(SENDINBLUE_WC_LOGS_HASH, null) !== null) {
            update_option(SENDINBLUE_WC_LOGS_HASH, $hash);
        } else {
            add_option(SENDINBLUE_WC_LOGS_HASH, $hash);
        }

        return $hash;
    }

    /**
     * Hash used in log file names, derived one-way from the site hash.
     *
     * File names travel: the backend API returns them in list mode and echoes
     * one on every read. With the directory's own hash in each name, a single
     * disclosed file name plus the directory's enumerable timestamp would be
     * the directory URL — and on nginx that URL is the only access control.
     * HMAC keeps the two independent: the file hash reveals nothing about the
     * directory's, and file names stay unguessable in their own right.
     *
     * @return string
     */
    public function get_file_hash()
    {
        return substr(hash_hmac('sha256', 'file-name', $this->get_site_hash()), 0, self::HASH_LENGTH);
    }

    /**
     * Write the guard files that block direct HTTP access where the web server
     * honours them.
     *
     * Apache reads .htaccess. IIS reads web.config. **nginx reads neither** —
     * on nginx the only thing standing between a log file and the public
     * internet is the hash in the path. index.html additionally suppresses
     * directory listing where it is enabled.
     *
     * @param string $path
     * @return void
     */
    private function ensure_protected($path)
    {
        $path = trailingslashit($path);

        $guards = array(
            '.htaccess'  => "# Brevo for WooCommerce - debug logs, not for public access.\n"
                . "<IfModule mod_authz_core.c>\n"
                . "\tRequire all denied\n"
                . "</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n"
                . "\tOrder allow,deny\n"
                . "\tDeny from all\n"
                . "</IfModule>\n",
            'index.html' => '',
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
                . "<configuration>\n"
                . "\t<system.webServer>\n"
                . "\t\t<authorization>\n"
                . "\t\t\t<deny users=\"*\" />\n"
                . "\t\t</authorization>\n"
                . "\t</system.webServer>\n"
                . "</configuration>\n",
        );

        foreach ($guards as $name => $contents) {
            $file = $path . $name;
            if (!file_exists($file)) {
                @file_put_contents($file, $contents, LOCK_EX);
            }
        }
    }

    /**
     * Whether logs can currently be written and read.
     *
     * @return bool
     */
    public function is_available()
    {
        return $this->get_directory() !== null;
    }

    /**
     * Log file name for a given day.
     *
     * @param string|null $date Y-m-d, defaults to today in UTC — same clock
     *                          as the record timestamps, so a file's name
     *                          always matches the dates of the lines in it.
     * @return string
     */
    public function build_file_name($date = null)
    {
        if (empty($date)) {
            $date = gmdate('Y-m-d');
        }

        return self::FILE_PREFIX . $date . '-' . $this->get_file_hash() . self::FILE_EXTENSION;
    }

    /**
     * Reject anything that is not one of our own log file names.
     *
     * Guards the backend read path against traversal and against being pointed
     * at unrelated files that happen to sit in the same directory.
     *
     * @param string $name
     * @return bool
     */
    public function is_valid_file_name($name)
    {
        if (!is_string($name) || $name === '' || $name !== basename($name)) {
            return false;
        }

        $pattern = '/^' . preg_quote(self::FILE_PREFIX, '/')
            . '\d{4}-\d{2}-\d{2}-[A-Za-z0-9]{' . self::HASH_LENGTH . '}'
            . preg_quote(self::FILE_EXTENSION, '/') . '$/';

        return (bool) preg_match($pattern, $name);
    }

    /**
     * Resolve a log file name to an absolute path inside our directory.
     *
     * @param string $name
     * @return string|null
     */
    private function resolve_file($name)
    {
        // Reads never create: an existing directory or nothing.
        $directory = $this->get_existing_directory();
        if ($directory === null || !$this->is_valid_file_name($name)) {
            return null;
        }

        $path = $directory . $name;

        // Belt and braces: even with the name validated, confirm the resolved
        // path really sits inside the log directory before touching it.
        $real_path = realpath($path);
        $real_dir = realpath($directory);
        if ($real_path === false || $real_dir === false) {
            return null;
        }
        if (strpos($real_path, trailingslashit($real_dir)) !== 0) {
            return null;
        }

        return $real_path;
    }

    /**
     * Append one already-encoded line.
     *
     * This is deliberately dumb: it does not decide whether logging is
     * enabled and it does not touch the content. Callers do both.
     *
     * @param string $line Single line, without a trailing newline.
     * @return bool True when the line reached disk.
     */
    public function append($line)
    {
        return $this->safe(function () use ($line) {
            return $this->do_append($line);
        });
    }

    /**
     * @param string $line
     * @return bool
     */
    private function do_append($line)
    {
        $directory = $this->get_directory();
        if ($directory === null || !is_string($line) || $line === '') {
            return false;
        }

        // Belt and braces: the write sinks already redact, but running the
        // shape pass here makes it structural — an unredacted credential
        // cannot reach disk through any current or future caller. Idempotent
        // ([redacted] never re-matches) and replacement text is JSON-safe.
        $line = $this->redact($line);

        // get_directory() only checks writability the first time it resolves
        // the path and then memoises it for the rest of the request, so a
        // directory made read-only mid-request (a permission fix gone wrong,
        // a security scanner, an admin locking things down) would otherwise
        // go undetected here. Appending to a file that already exists does
        // not itself require the directory to be writable, so re-check it
        // explicitly rather than let that fall through to disk.
        clearstatcache(true, $directory);
        if (!is_writable($directory)) {
            return false;
        }

        // A newline inside the payload would split one record into two and
        // break the JSON Lines contract the reader depends on.
        $line = str_replace(array("\r", "\n"), ' ', $line);

        // Reject rather than truncate. Cutting a line at a byte boundary would
        // leave invalid JSON — and a malformed line is worse than a missing
        // one, because the backend cannot parse past it. Keeping lines under
        // the cap is the caller's job.
        if (strlen($line) > self::MAX_LINE_BYTES) {
            return false;
        }

        $file = $directory . $this->build_file_name();

        // prune() only runs at day rollover, so it alone cannot stop one very
        // chatty day from growing without bound. Once the day's file cannot
        // take this line without crossing the ceiling, stop writing — keeping
        // the earliest lines of an incident beats an unbounded file on a
        // merchant's disk. The incoming bytes count too: the cap is a hard
        // ceiling, not a threshold the last line may overshoot.
        clearstatcache(false, $file);
        $size = @filesize($file);
        if ($size === false) {
            $size = 0;
        }
        if ($size + strlen($line) + 1 > self::MAX_TOTAL_BYTES) {
            return false;
        }

        $written = @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);

        return $written !== false;
    }

    // ------------------------------------------------------------- gate ----

    /**
     * Whether logging is currently on.
     *
     * Fail-closed: anything unexpected answers "no". The expiry is checked
     * here, on the write path, rather than by a scheduled job — WP-Cron is
     * driven by page loads and would not fire on a quiet shop, which is
     * exactly where an unbounded window would do the most damage.
     *
     * @return bool
     */
    public function is_enabled()
    {
        if ($this->enabled !== null) {
            return $this->enabled;
        }

        $this->enabled = false;

        // Escape hatch for local development and support reproduction. Setting
        // it requires wp-config.php access, i.e. the same bar as WP_DEBUG.
        if (defined('SENDINBLUE_WC_DEBUG_LOGS') && SENDINBLUE_WC_DEBUG_LOGS) {
            $this->enabled = true;

            return true;
        }

        if (get_option(SENDINBLUE_WC_LOGS_ENABLED, '0') !== '1') {
            return false;
        }

        $expires = (int) get_option(SENDINBLUE_WC_LOGS_EXPIRES, 0);

        if ($expires <= 0 || time() >= $expires) {
            // Window closed. Persist that so the shop stops re-checking, and
            // so the merchant-facing state is honest without Brevo involved.
            //
            // disable() is a wrapped public entry; is_enabled() is called
            // from inside do_log()/do_append()/etc. while the reentrancy
            // guard is already set, which would turn the public form into a
            // no-op — go straight to do_disable().
            //
            // is_enabled() itself is public, unwrapped, and called directly
            // by non-logging code (the REST/storefront path), so there is no
            // safe() boundary above THIS call the way there is above every
            // other do_disable() call site. do_disable() writes options and
            // flushes buffered notes to disk (do_flush_notes() ->
            // write_record() -> do_append()) — give it its own, independent
            // exception boundary so a \Throwable there cannot escape
            // is_enabled() and take the storefront down with it.
            try {
                $this->do_disable();
            } catch (\Throwable $e) {
                // no-op: logging must never take a storefront down.
            } catch (\Exception $e) {
                // PHP 5.6 has no \Throwable; this arm is dead on 7+.
            }

            return false;
        }

        $this->enabled = true;

        return true;
    }

    /**
     * Turn logging on for a bounded window.
     *
     * @param int $seconds Requested duration; clamped to MAX_WINDOW_SECONDS.
     * @return int The expiry timestamp actually stored.
     */
    public function enable($seconds = self::MAX_WINDOW_SECONDS)
    {
        return $this->safe(function () use ($seconds) {
            return $this->do_enable($seconds);
        }, 0);
    }

    /**
     * @param int $seconds
     * @return int
     */
    private function do_enable($seconds = self::MAX_WINDOW_SECONDS)
    {
        $seconds = (int) $seconds;
        if ($seconds <= 0) {
            $seconds = self::MAX_WINDOW_SECONDS;
        }
        $seconds = min($seconds, self::MAX_WINDOW_SECONDS);

        $expires = time() + $seconds;

        $this->set_option(SENDINBLUE_WC_LOGS_ENABLED, '1');
        $this->set_option(SENDINBLUE_WC_LOGS_EXPIRES, (string) $expires);

        $this->enabled = null;
        $this->forget_shared_state();

        // Each enable starts a fresh session directory, stamped with the time
        // it was switched on, so one debugging window's output is never mixed
        // with another's.
        $this->start_new_directory();

        // Create it now, while we are in whatever context switched logging on
        // — normally a web request, which gives it the ownership the web
        // server needs. Waiting for the first log line means a CLI or cron
        // write could get there first and create it as the wrong user.
        $this->get_directory();

        // Retention must not depend on anyone opening wp-admin: admin_init
        // never fires on a shop nobody administers, and the write path stops
        // with the window. A daily cron event fires on any front-end visit —
        // as close to a guarantee as WordPress gives without a system cron.
        // sweep() unschedules it once every session directory is gone.
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK);
        }

        // instance() only installs the error handler when a window is already
        // open; installing here as well means the request that switches
        // logging on starts capturing immediately. Idempotent (static guard).
        $this->install_error_handler();

        return $expires;
    }

    /**
     * Turn logging off and clear the window.
     *
     * @return void
     */
    public function disable()
    {
        return $this->safe(function () {
            return $this->do_disable();
        }, null);
    }

    /**
     * @return void
     */
    private function do_disable()
    {
        // Buffered notes would be unwritable once the switch flips; the
        // shared instance is where call sites accumulate them.
        //
        // flush_notes() is a wrapped public entry. do_disable() usually runs
        // inside an active safe() call (via disable()/apply_remote_flag()),
        // where the reentrancy guard would turn the public form into a
        // no-op — but it is also reached from is_enabled(), which calls it
        // directly with no safe() active at all (see is_enabled(), wrapped
        // in its own local try/catch there). Either way, go straight to
        // do_flush_notes() rather than through the public wrapper.
        $this->do_flush_notes();
        if (self::$instance !== null && self::$instance !== $this) {
            self::$instance->flush_notes();
        }

        $this->set_option(SENDINBLUE_WC_LOGS_ENABLED, '0');
        $this->set_option(SENDINBLUE_WC_LOGS_EXPIRES, '0');

        $this->enabled = false;
        $this->forget_shared_state();
    }

    /**
     * Decide what a remote (Brevo-propagated) flag value means, edge-triggered.
     *
     * Brevo pushes isPluginLogsEnabled on EVERY settings save, not only when it
     * changes. Acting on the value alone would restart the 24h window on every
     * unrelated save, and would undo a merchant's local "off" on the next sync.
     * Comparing against the last value Brevo sent makes both impossible: only a
     * real off->on edge enables, only a real on->off edge disables.
     *
     * @param bool        $incoming    value in the current /configs payload
     * @param string|null $last_remote last stored value ('1'/'0'), null if never seen
     *
     * @return string 'enable' | 'disable' | 'none'
     */
    public static function remote_transition($incoming, $last_remote)
    {
        if ($incoming && $last_remote !== '1') {
            return 'enable';
        }

        if (!$incoming && $last_remote === '1') {
            return 'disable';
        }

        return 'none';
    }

    /**
     * Apply a remote flag value from a /configs payload.
     *
     * Enables or disables only on a genuine edge (see remote_transition()),
     * then stores the value as the new remote memory. The window length is
     * always the plugin-side clamp — whatever Brevo sends cannot extend it.
     *
     * The switch itself is recorded: on disable the record is written BEFORE
     * the gate closes (a line written after disable() would be dropped), on
     * enable it is the first record of the fresh session.
     *
     * First-sight behavior: on a shop where the remote memory option does
     * not exist yet (never seen, or just cleared by a disconnect), the first
     * push only seeds that memory and returns 'none' — no enable/disable
     * happens on that call, whatever value was pushed.
     *
     * @param bool   $incoming
     * @param string $source   who flipped the switch, for the audit line
     *
     * @return string the transition that was applied ('enable'|'disable'|'none')
     */
    public function apply_remote_flag($incoming, $source = 'brevo')
    {
        return $this->safe(function () use ($incoming, $source) {
            return $this->do_apply_remote_flag($incoming, $source);
        }, 'none');
    }

    /**
     * @param bool   $incoming
     * @param string $source
     * @return string
     */
    private function do_apply_remote_flag($incoming, $source = 'brevo')
    {
        $incoming = (bool) $incoming;
        $last_remote = get_option(SENDINBLUE_WC_LOGS_REMOTE, null);

        if ($last_remote === null) {
            // First sight of the flag on this shop. Brevo may already hold
            // true from before the logging code existed; acting on it would
            // start capturing PII on the first settings push after the
            // update, with nobody having asked. Seed the memory and wait
            // for a real edge. Cost: the first genuine FE enable on a shop
            // needs one off->on retoggle.
            $this->set_option(SENDINBLUE_WC_LOGS_REMOTE, $incoming ? '1' : '0');

            return 'none';
        }

        $transition = self::remote_transition($incoming, is_string($last_remote) ? $last_remote : null);

        // enable()/disable()/log() are wrapped public entries; do_apply_remote_flag()
        // always runs inside an active safe() call, so the reentrancy guard
        // would turn the public forms into no-ops here.
        if ($transition === 'enable') {
            $this->do_enable();
            $this->do_log(self::LEVEL_INFO, 'settings', 'debug logging switched on', array('source' => $source));
        } elseif ($transition === 'disable') {
            $this->do_log(self::LEVEL_INFO, 'settings', 'debug logging switching off', array('source' => $source));
            $this->do_disable();
        }

        // Memory is written after the transition: if the request dies inside
        // enable()/disable(), the memory must not already claim the new state
        // — a retry would then be a repeat and land in a dead end.
        $this->set_option(SENDINBLUE_WC_LOGS_REMOTE, $incoming ? '1' : '0');

        return $transition;
    }

    /**
     * Drop the shared instance's cached enable state and directory.
     *
     * Every call site logs through instance(), which memoises both for the
     * request. When something else flips the switch — the toggle endpoint, a
     * WP-CLI call — that memo is stale, and logging either keeps writing after
     * it was turned off or stays silent after it was turned on.
     *
     * @return void
     */
    private function forget_shared_state()
    {
        if (self::$instance === null || self::$instance === $this) {
            return;
        }

        self::$instance->enabled = null;
        self::$instance->directory = null;
    }

    /**
     * Timestamp at which logging stops, or 0 when it is not running.
     *
     * @return int
     */
    public function get_expiry()
    {
        if (!$this->is_enabled()) {
            return 0;
        }

        return (int) get_option(SENDINBLUE_WC_LOGS_EXPIRES, 0);
    }

    /**
     * add_option/update_option in one call.
     *
     * @param string $key
     * @param string $value
     * @return void
     */
    private function set_option($key, $value)
    {
        if (get_option($key, null) === null) {
            add_option($key, $value);

            return;
        }

        update_option($key, $value);
    }

    // ------------------------------------------------------------ writer ----

    /**
     * The single exception boundary. Logging must never take a storefront
     * down (design note 5): every public entry funnels through here, so a
     * PHP 7+ \Error (TypeError inside sanitize(), wp_json_encode() edge
     * cases) degrades to the fallback exactly like an \Exception does.
     *
     * @param callable $fn
     * @param mixed    $fallback
     * @return mixed
     */
    private function safe($fn, $fallback = false)
    {
        if ($this->reentrant) {
            return $fallback;
        }

        $this->reentrant = true;

        try {
            return call_user_func($fn);
        } catch (\Throwable $e) {
            return $fallback;
        } catch (\Exception $e) {
            // PHP 5.6 has no \Throwable; this arm is dead on 7+.
            return $fallback;
        } finally {
            $this->reentrant = false;
        }
    }

    /**
     * Record one event.
     *
     * Safe to call unconditionally from anywhere in the plugin: it returns
     * immediately when logging is off, and every failure below is swallowed.
     *
     * Debug detail does not get its own line. It is buffered via note() and
     * flushed at the end of the request as ONE summary record with an
     * accumulated info_message — the same style as the consumer contact-sync
     * logs. Only info/warn events and errors write discrete records.
     *
     * @param string $level   One of the LEVEL_* constants.
     * @param string $channel Coarse grouping, e.g. 'http', 'rest', 'order'.
     * @param string $message Short human-readable summary.
     * @param array  $context Structured detail, logged as-is (empties pruned).
     * @return bool
     */
    public function log($level, $channel, $message, $context = array())
    {
        return $this->safe(function () use ($level, $channel, $message, $context) {
            return $this->do_log($level, $channel, $message, $context);
        });
    }

    /**
     * @param string $level
     * @param string $channel
     * @param string $message
     * @param array  $context
     * @return bool
     */
    private function do_log($level, $channel, $message, $context = array())
    {
        if (!$this->is_enabled()) {
            return false;
        }

        $level = (string) $level;

        if ($level === self::LEVEL_DEBUG) {
            return $this->do_note($channel, $message, $context);
        }

        if ($level === self::LEVEL_ERROR) {
            return $this->write_record($level, $channel, null, $message, $context);
        }

        return $this->write_record($level, $channel, $message, null, $context);
    }

    /**
     * Build and append one record.
     *
     * Field names deliberately mirror the structured contact-sync logs in
     * integration-events-consumers (see header note 4). A record can carry
     * info_message, error_message or — for the flushed request summary —
     * both at once.
     *
     * @param string      $level
     * @param string      $channel
     * @param string|null $info_message
     * @param string|null $error_message
     * @param array       $context
     * @return bool
     */
    private function write_record($level, $channel, $info_message, $error_message, $context = array())
    {
        if (!$this->is_enabled()) {
            return false;
        }

        $record = array(
            'date'    => $this->timestamp(),
            'rid'     => self::request_id(),
            'level'   => (string) $level,
            'channel' => (string) $channel,
        );

        if ($info_message !== null && $info_message !== '') {
            $record['info_message'] = $this->redact((string) $info_message);
        }
        if ($error_message !== null && $error_message !== '') {
            $record['error_message'] = $this->redact((string) $error_message);
            $record['isErr'] = true;
        }

        // Empty fields are dropped rather than written as null — a record
        // should carry only what it has to say.
        $ucid = $this->user_connection_id();
        if ($ucid !== null) {
            $record['userConnectionId'] = $ucid;
        }

        $record['connector'] = 'woocommerce';

        $host = $this->shop_host();
        if ($host !== null) {
            $record['shopHost'] = $host;
        }

        if (!empty($context) && is_array($context)) {
            $context = $this->sanitize($context);

            // Callers that time an operation pass 'ms'; surface it under
            // the name the consumer logs use so durations query the same
            // way on both sides.
            if (isset($context['ms']) && is_numeric($context['ms'])) {
                $record['time_processing'] = (int) round($context['ms']);
                unset($context['ms']);
            }

            if (!empty($context)) {
                $record['data'] = $context;
            }
        }

        $line = wp_json_encode($record);
        if ($line === false || $line === null) {
            return false;
        }

        // First write of the day rotates onto a new file; a good moment to
        // enforce retention without paying for it on every single call.
        $directory = $this->get_directory();
        if ($directory !== null && !file_exists($directory . $this->build_file_name())) {
            $this->prune();
        }

        // append() is a wrapped public entry; write_record() always runs
        // inside an active safe() call (from do_log()/do_flush_notes()), so
        // the reentrancy guard would turn the public form into a no-op here.
        return $this->do_append($line);
    }

    // ------------------------------------------------- request summary ----

    /**
     * Append one step to the request narrative instead of writing a line.
     *
     * The buffer is flushed at shutdown as a single record whose info_message
     * is the accumulated story of the request — the info_message += style the
     * consumer contact-sync logs use.
     *
     * @param string $channel
     * @param string $message
     * @param array  $context Encoded inline after the message.
     * @return bool
     */
    public function note($channel, $message, $context = array())
    {
        return $this->safe(function () use ($channel, $message, $context) {
            return $this->do_note($channel, $message, $context);
        }, null);
    }

    /**
     * @param string $channel
     * @param string $message
     * @param array  $context
     * @return bool
     */
    private function do_note($channel, $message, $context = array())
    {
        return $this->buffer_note($this->request_notes, $channel, $message, $context);
    }

    /**
     * @param string[] $buffer Taken by reference.
     * @param string   $channel
     * @param string   $message
     * @param array    $context
     * @return bool
     */
    private function buffer_note(&$buffer, $channel, $message, $context)
    {
        if (!$this->is_enabled()) {
            return false;
        }

        if (count($this->request_notes) + count($this->request_errors) >= self::MAX_NOTES) {
            $this->notes_dropped++;

            return false;
        }

        $line = '[' . $channel . '] ' . $this->redact($message);

        if (!empty($context) && is_array($context)) {
            $context = $this->sanitize($context);
            if (!empty($context)) {
                $encoded = wp_json_encode($context);
                if (is_string($encoded)) {
                    $line .= ' ' . $encoded;
                }
            }
        }

        $buffer[] = $line;

        if (!$this->flush_hooked) {
            $this->flush_hooked = true;
            register_shutdown_function(array($this, 'shutdown_flush'));
        }

        return true;
    }

    /**
     * Write the buffered request narrative as one summary record.
     *
     * Runs at shutdown; safe to call earlier (disable() does) and to call
     * twice — the buffer is consumed on the first flush.
     *
     * @return bool
     */
    public function flush_notes()
    {
        return $this->safe(function () {
            return $this->do_flush_notes();
        }, null);
    }

    /**
     * Shutdown-time flush that survives a mid-call fatal.
     *
     * finally does not run on a true fatal (OOM, max_execution_time), so a
     * fatal inside a wrapped entry leaves the reentrancy flag set — and a
     * flush routed straight through safe() would then no-op, dropping the
     * buffered narrative in exactly the request it matters for. Shutdown is
     * a fresh top-level entry: clear the flag first, then flush normally.
     *
     * @return void
     */
    public function shutdown_flush()
    {
        $this->reentrant = false;
        $this->flush_notes();
    }

    /**
     * @return bool
     */
    private function do_flush_notes()
    {
        if (empty($this->request_notes) && empty($this->request_errors)) {
            return false;
        }

        $notes = $this->request_notes;
        $errors = $this->request_errors;
        $this->request_notes = array();
        $this->request_errors = array();

        if ($this->notes_dropped > 0) {
            $notes[] = '(+' . $this->notes_dropped . ' more, buffer capped)';
            $this->notes_dropped = 0;
        }

        $context = array();
        if (isset($_SERVER['REQUEST_METHOD'])) {
            $context['method'] = (string) $_SERVER['REQUEST_METHOD'];
        }
        if (isset($_SERVER['REQUEST_URI'])) {
            // Path only — query strings can carry REST credentials and
            // campaign/tracking identifiers; the summary must not widen what
            // lands in the file beyond the disclosed record types.
            $context['uri'] = (string) wp_parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
        }

        return $this->write_record(
            empty($errors) ? self::LEVEL_INFO : self::LEVEL_ERROR,
            'request',
            empty($notes) ? null : implode('; ', $notes),
            empty($errors) ? null : implode('; ', $errors),
            $context
        );
    }

    /**
     * UTC timestamp with millisecond precision.
     *
     * Second resolution is not enough: a single page load can emit a dozen
     * records, and ordering them is most of what reading a log is.
     *
     * @return string
     */
    private function timestamp()
    {
        $now = microtime(true);
        $whole = (int) floor($now);
        $ms = (int) floor(($now - $whole) * 1000);

        return gmdate('Y-m-d\TH:i:s', $whole) . sprintf('.%03dZ', $ms);
    }

    /**
     * Brevo connection id stamped on every record.
     *
     * This is the join key between plugin logs and the consumer-side sync logs;
     * a line without it cannot be correlated. Read fresh on every call rather
     * than memoised: get_option() is served from WordPress's in-memory options
     * cache, and connect/disconnect can flip the value mid-request.
     *
     * @return string|null
     */
    private function user_connection_id()
    {
        if (!defined('SENDINBLUE_WC_USER_CONNECTION_ID') || !function_exists('get_option')) {
            return null;
        }

        $raw = get_option(SENDINBLUE_WC_USER_CONNECTION_ID, null);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $clean = preg_replace('/[^a-zA-Z0-9]/', '', $raw);

        return ($clean === '' || $clean === null) ? null : $clean;
    }

    /**
     * Host part of the shop URL, memoised per request — it cannot change
     * mid-request and get_home_url() runs filters on every call.
     *
     * @var string|null|false false = not resolved yet
     */
    private static $shop_host = false;

    /** @return string|null */
    private function shop_host()
    {
        if (self::$shop_host === false) {
            self::$shop_host = null;

            if (function_exists('get_home_url')) {
                $host = parse_url((string) get_home_url(), PHP_URL_HOST);
                if (is_string($host) && $host !== '') {
                    self::$shop_host = $host;
                }
            }
        }

        return self::$shop_host;
    }

    /** @return bool */
    public function debug($channel, $message, $context = array())
    {
        return $this->log(self::LEVEL_DEBUG, $channel, $message, $context);
    }

    /** @return bool */
    public function info($channel, $message, $context = array())
    {
        return $this->log(self::LEVEL_INFO, $channel, $message, $context);
    }

    /** @return bool */
    public function warn($channel, $message, $context = array())
    {
        return $this->log(self::LEVEL_WARN, $channel, $message, $context);
    }

    /** @return bool */
    public function error($channel, $message, $context = array())
    {
        return $this->log(self::LEVEL_ERROR, $channel, $message, $context);
    }

    // ----------------------------------------------- context sanitising ----

    /**
     * Context keys whose VALUES are always credentials, at any depth. Payload
     * values are otherwise logged as-is (owner decision) — this denylist is
     * the one exception, and it is keys only, never a general redactor.
     *
     * Object property names route through here too: is_object() below
     * converts via get_object_vars() and re-enters this same array branch,
     * so a property called consumer_secret is caught by the identical check
     * that catches an array key of the same name.
     *
     * @var string[]
     */
    private static $secret_keys = array(
        'apikeyv3', 'api_key', 'consumer_key', 'consumer_secret',
        'consumerkey', 'consumersecret', 'ma_key', 'authorization', 'password',
        'marketingautomationkey', 'ma-key', 'api-key',
    );

    /**
     * Strip credential SHAPES out of a string. Values-as-is stays the policy
     * for payload data (owner decision); a bare key/token embedded in a URL
     * or query string is not payload, so it is stripped wherever it appears,
     * independent of the key denylist above.
     *
     * Note the staging key prefix is sw_, not WooCommerce's ck_/cs_ default —
     * both are covered by the same pattern.
     *
     * @param mixed $value
     * @return mixed Unchanged unless $value is a non-empty string.
     */
    private function redact($value)
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $redacted = preg_replace(
            array(
                '/xkeysib-[A-Za-z0-9-]+/',
                '/xsmtpsib-[A-Za-z0-9-]+/',
                '/\b(sw|ck|cs)_[a-f0-9]{40}\b/',
                '/(consumer_(?:key|secret)=)[^&"\'\s]+/',
            ),
            array(
                'xkeysib-[redacted]',
                'xsmtpsib-[redacted]',
                '$1_[redacted]',
                '$1[redacted]',
            ),
            $value
        );

        // preg_replace returns null on a PCRE failure (e.g. backtrack limit
        // on a pathological string). A null here would erase the line, and a
        // logged-but-unredacted value inside safe() beats a silently dropped
        // record only when the caller keyed it as a secret — those were
        // already replaced wholesale before redact() ran, so falling back to
        // the original string is the safe branch.
        return $redacted === null ? $value : $redacted;
    }

    /**
     * Make a context tree safe to JSON-encode and drop the noise.
     *
     * Values are logged AS IS — no general masking. What this walk does do:
     * - bounds depth so a self-referencing structure cannot recurse forever
     * - flattens objects and replaces unencodable values (resources, closures)
     * - prunes empties (null, '', empty arrays like meta_data: []) so records
     *   carry only fields that say something; 0 and false are kept — they are
     *   answers, not absences
     * - replaces the VALUE of any key in $secret_keys with '[redacted]'
     *   without recursing into it, and strips credential shapes (API keys,
     *   consumer_key/secret query values) out of every remaining string
     *   scalar via redact()
     *
     * @param mixed $value
     * @param int   $depth
     * @return mixed
     */
    public function sanitize($value, $depth = 0)
    {
        if ($depth > 8) {
            return '[truncated]';
        }

        if (is_array($value)) {
            $out = array();
            foreach ($value as $key => $item) {
                if (in_array(strtolower((string) $key), self::$secret_keys, true)) {
                    $out[$key] = '[redacted]';
                    continue;
                }

                $item = $this->sanitize($item, $depth + 1);
                if ($item === null || $item === '' || $item === array()) {
                    continue;
                }
                $out[$key] = $item;
            }

            return $out;
        }

        if (is_object($value)) {
            return $this->sanitize(get_object_vars($value), $depth + 1);
        }

        if (is_string($value)) {
            return $this->redact($value);
        }

        if (is_scalar($value) || $value === null) {
            return $value;
        }

        return '[unloggable]';
    }

    /**
     * Log files currently on disk, oldest first.
     *
     * Listing is a read: it never creates the directory, so it is safe on a
     * shop that has never logged.
     *
     * @return array List of array{name: string, size: int, modified: int}
     */
    public function list_files()
    {
        return $this->safe(function () {
            return $this->do_list_files();
        }, array());
    }

    /**
     * @return array List of array{name: string, size: int, modified: int}
     */
    private function do_list_files()
    {
        $directory = $this->get_existing_directory();
        if ($directory === null) {
            return array();
        }

        $paths = glob($directory . self::FILE_PREFIX . '*' . self::FILE_EXTENSION);
        if (!is_array($paths)) {
            return array();
        }

        $files = array();
        foreach ($paths as $path) {
            $name = basename($path);
            if (!$this->is_valid_file_name($name) || !is_file($path)) {
                continue;
            }

            $files[] = array(
                'name'     => $name,
                'size'     => (int) filesize($path),
                'modified' => (int) filemtime($path),
            );
        }

        usort($files, array($this, 'compare_by_name'));

        return $files;
    }

    /**
     * Whether any log files are still on disk, regardless of the enable state.
     *
     * This is what lets a capture outlive its window: files survive for
     * RETENTION_DAYS after the 24h window closes, and as long as they exist
     * the backend may still fetch them.
     *
     * @return bool
     */
    public function has_retained_files()
    {
        $files = $this->list_files();

        return !empty($files);
    }

    /**
     * @param array $a
     * @param array $b
     * @return int
     */
    private function compare_by_name($a, $b)
    {
        return strcmp($a['name'], $b['name']);
    }

    /**
     * Read complete lines from a log file, starting at a byte offset.
     *
     * This is the read side of the backend cursor. It never returns a partial
     * trailing line, so a fetch racing an in-flight append cannot hand back
     * truncated JSON — the partial line is simply left for the next call.
     *
     * @param string $name      Log file name.
     * @param int    $offset    Byte offset to resume from.
     * @param int    $max_bytes Read window.
     * @return array|null array{lines: array, next_offset: int, has_more: bool, size: int}
     */
    public function read_file($name, $offset = 0, $max_bytes = self::READ_CHUNK_DEFAULT)
    {
        return $this->safe(function () use ($name, $offset, $max_bytes) {
            return $this->do_read_file($name, $offset, $max_bytes);
        }, null);
    }

    /**
     * @param string $name
     * @param int    $offset
     * @param int    $max_bytes
     * @return array|null array{lines: array, next_offset: int, has_more: bool, size: int}
     */
    private function do_read_file($name, $offset = 0, $max_bytes = self::READ_CHUNK_DEFAULT)
    {
        $path = $this->resolve_file($name);
        if ($path === null) {
            return null;
        }

        $offset = max(0, (int) $offset);
        $max_bytes = (int) $max_bytes;
        if ($max_bytes <= 0) {
            $max_bytes = self::READ_CHUNK_DEFAULT;
        }
        $max_bytes = min($max_bytes, self::READ_CHUNK_MAX);

        // PHP caches stat results. Without this a read that follows an append
        // in the same request sees a stale size and reports has_more = false
        // while bytes are still pending.
        clearstatcache(true, $path);

        $size = (int) filesize($path);

        $empty = array(
            'lines'       => array(),
            'next_offset' => min($offset, $size),
            'has_more'    => false,
            'size'        => $size,
        );

        if ($offset >= $size) {
            return $empty;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return $empty;
        }

        if (fseek($handle, $offset) !== 0) {
            fclose($handle);
            return $empty;
        }

        $chunk = fread($handle, $max_bytes);
        if ($chunk === false || $chunk === '') {
            fclose($handle);
            return $empty;
        }

        $last_newline = strrpos($chunk, "\n");

        if ($last_newline === false) {
            // No complete line inside the window. Normally this just means the
            // only thing ahead of us is a partial line still being written, and
            // we wait. But if we filled the whole window without seeing a
            // newline, a single oversized line is in the way and the cursor
            // would never advance — so step over it.
            if (strlen($chunk) >= $max_bytes) {
                $skipped = $this->seek_past_line($handle, $offset + strlen($chunk));
                fclose($handle);

                return array(
                    'lines'       => array(),
                    'next_offset' => $skipped === null ? $size : $skipped,
                    'has_more'    => $skipped !== null && $skipped < $size,
                    'size'        => $size,
                );
            }

            fclose($handle);
            return $empty;
        }

        fclose($handle);

        $complete = substr($chunk, 0, $last_newline + 1);
        $next_offset = $offset + strlen($complete);

        $lines = array();
        foreach (explode("\n", $complete) as $line) {
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return array(
            'lines'       => $lines,
            'next_offset' => $next_offset,
            'has_more'    => $next_offset < $size,
            'size'        => $size,
        );
    }

    /**
     * Scan forward from a position to just past the next newline.
     *
     * Only used to escape an oversized line that would otherwise stall the
     * cursor forever.
     *
     * @param resource $handle
     * @param int      $from
     * @return int|null Offset just past the newline, or null if none remains.
     */
    private function seek_past_line($handle, $from)
    {
        if (fseek($handle, $from) !== 0) {
            return null;
        }

        while (($chunk = fread($handle, self::READ_CHUNK_DEFAULT)) !== false && $chunk !== '') {
            $newline = strpos($chunk, "\n");
            if ($newline !== false) {
                return $from + $newline + 1;
            }
            $from += strlen($chunk);
        }

        return null;
    }

    /**
     * Enforce retention and the total size ceiling.
     *
     * @return void
     */
    public function prune()
    {
        $this->prune_old_sessions();

        $directory = $this->get_directory();
        if ($directory === null) {
            return;
        }

        // list_files() is a wrapped public entry; prune() is only ever
        // called from write_record(), which always runs inside an active
        // safe() call, so the reentrancy guard would turn the public form
        // into a no-op here.
        $files = $this->do_list_files();
        if (empty($files)) {
            return;
        }

        $cutoff = time() - (self::RETENTION_DAYS * DAY_IN_SECONDS);

        $remaining = array();
        $total = 0;

        foreach ($files as $file) {
            if ($file['modified'] < $cutoff) {
                @unlink($directory . $file['name']);
                continue;
            }

            $remaining[] = $file;
            $total += $file['size'];
        }

        // list_files() is sorted by name, which for our naming is chronological,
        // so dropping from the front drops the oldest first.
        foreach ($remaining as $file) {
            if ($total <= self::MAX_TOTAL_BYTES) {
                break;
            }

            @unlink($directory . $file['name']);
            $total -= $file['size'];
        }
    }

    /**
     * Enforce retention while logging is off.
     *
     * prune() runs only from the write path, so once a window lapses nothing
     * writes and nothing would ever delete what it captured — and the one-shot
     * support flow (enable, grab the file, done) is exactly the one where the
     * readme's retention promise must still hold. This is the path that runs
     * with logging off — from admin_init, and from the daily CRON_HOOK event
     * so shops whose wp-admin nobody opens are still covered. Unlike prune()
     * it may remove the current session directory too: with logging off,
     * "current" only means "most recent".
     *
     * Deliberately never calls get_directory() — a sweep on a shop that has
     * never logged must not mint a session directory as a side effect. The
     * glob inside prune_old_sessions() reads only.
     *
     * @return void
     */
    public function sweep()
    {
        return $this->safe(function () {
            return $this->do_sweep();
        }, null);
    }

    /**
     * @return void
     */
    private function do_sweep()
    {
        // is_enabled() first: it carries the lazy window-expiry side effect,
        // which must run even on shops the guard below sends home early.
        if ($this->is_enabled()) {
            return;
        }

        // sweep() runs on every admin_init, but logging is off by default and
        // most shops never turn it on — they should not pay for a glob of the
        // uploads dir on each wp-admin request. Every minted session directory
        // stores SENDINBLUE_WC_LOGS_DIR, and the option is only deleted while
        // the daily cron is still scheduled, so "no option and no cron" means
        // there is nothing on disk to find.
        if (get_option(SENDINBLUE_WC_LOGS_DIR, null) === null
            && !wp_next_scheduled(self::CRON_HOOK)
        ) {
            return;
        }

        $this->prune_old_sessions(true);
        $this->directory = null;

        // Once nothing is left to delete the scheduled sweep has no purpose;
        // drop it so shops don't carry a permanent cron entry for a feature
        // support used once.
        if (wp_next_scheduled(self::CRON_HOOK)
            && count($this->find_session_directories()) === 0
        ) {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
    }

    /**
     * Drop session directories whose window closed longer ago than the
     * retention period.
     *
     * The current session is left alone unless $include_current — the write
     * path must never delete the directory it is writing into; the off-path
     * sweep has no such constraint.
     *
     * @param bool $include_current
     * @return void
     */
    private function prune_old_sessions($include_current = false)
    {
        $directories = $this->find_session_directories();
        if (empty($directories)) {
            return;
        }

        $current = get_option(SENDINBLUE_WC_LOGS_DIR, null);
        $cutoff = time() - (self::RETENTION_DAYS * DAY_IN_SECONDS);

        foreach ($directories as $path) {
            if (!$include_current && basename($path) === $current) {
                continue;
            }

            $modified = @filemtime($path);
            if ($modified !== false && $modified >= $cutoff) {
                continue;
            }

            $this->remove_directory($path);

            // Nothing may keep pointing at a deleted directory: the next
            // enable() mints a fresh name anyway, and a stale option would
            // make get_directory() recreate an empty husk on the next write.
            if (basename($path) === $current) {
                delete_option(SENDINBLUE_WC_LOGS_DIR);
            }
        }
    }

    /**
     * Every session directory belonging to this plugin, current one included.
     *
     * @return array Absolute paths.
     */
    private function find_session_directories()
    {
        $uploads = wp_upload_dir();
        if (empty($uploads['basedir'])) {
            return array();
        }

        $matches = glob(trailingslashit($uploads['basedir']) . self::DIR_PREFIX . '*', GLOB_ONLYDIR);
        if (!is_array($matches)) {
            return array();
        }

        $directories = array();
        foreach ($matches as $path) {
            // Only touch directories we know we created.
            if ($this->is_valid_directory_name(basename($path))) {
                $directories[] = $path;
            }
        }

        return $directories;
    }

    /**
     * Delete a session directory and everything in it.
     *
     * @param string $path
     * @return void
     */
    private function remove_directory($path)
    {
        if (!is_dir($path)) {
            return;
        }

        $path = trailingslashit($path);

        $entries = glob($path . '*');
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if (is_file($entry)) {
                    @unlink($entry);
                }
            }
        }

        // Guard files are dot-prefixed, so glob('*') misses them.
        foreach (array('.htaccess') as $hidden) {
            if (is_file($path . $hidden)) {
                @unlink($path . $hidden);
            }
        }

        @rmdir($path);
    }

    /**
     * Remove every session directory. Used on uninstall so a removed plugin
     * does not leave shop data behind.
     *
     * @return void
     */
    public function delete_all()
    {
        return $this->safe(function () {
            return $this->do_delete_all();
        }, null);
    }

    /**
     * @return void
     */
    private function do_delete_all()
    {
        foreach ($this->find_session_directories() as $path) {
            $this->remove_directory($path);
        }

        $this->directory = null;
    }
}
