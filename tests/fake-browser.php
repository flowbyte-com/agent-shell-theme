<?php
/**
 * A fake headless browser, shared by the screenshot suites.
 *
 * A real Chrome cannot be used here: it is not installed everywhere, its output
 * is not byte-stable, and rendering a real page would mean pointing the tests at
 * a live server. What the capture code actually depends on is a process that
 * accepts `--screenshot=<path>`, writes a PNG there, and exits — so that is
 * what this is.
 *
 * Behaviour is read from the environment by the script itself, so a single
 * spawned process can be told how to behave:
 *
 *   AGENTSHELL_FAKE_CHROME_MODE   success | hang | fail | garbage | truncate
 *   AGENTSHELL_FAKE_PNG_BYTES     size of the PNG body, default 200
 *   AGENTSHELL_FAKE_CHROME_ARGV   file to record the received argv in
 *   AGENTSHELL_FAKE_CHROME_ENV    file to record HOME/XDG as received
 *   AGENTSHELL_FAKE_CHROME_CACHE  write this text into the profile directory
 *   AGENTSHELL_FAKE_CHROME_PROFILE_MODE  record the profile's permission bits
 *
 * The size knob exists for the inline size-limit tests, which need a capture
 * above and below the limit without shipping a multi-megabyte fixture.
 *
 * The rest exist for the browser-isolation suite. A real Chrome cannot be
 * inspected here, and the property under test is exactly what the process was
 * *handed* — so the fake records it the way Chrome would experience it: reading
 * its own argv and environment, and writing into the profile directory it was
 * given, the way a real browser writes its cache.
 */

if ( ! function_exists( 'agentshell_fake_browser_path' ) ) {
    function agentshell_fake_browser_path( $dir ) {
        $path = $dir . '/fake-browser.sh';
        if ( ! file_exists( $path ) ) {
            $script = <<<'SH'
#!/bin/bash
# Fake headless browser. Parses --screenshot=<path>, behaves per mode.
OUT=""
PROFILE=""
for a in "$@"; do
  case "$a" in
    --screenshot=*) OUT="${a#--screenshot=}" ;;
    --user-data-dir=*) PROFILE="${a#--user-data-dir=}" ;;
  esac
done
MODE="${AGENTSHELL_FAKE_CHROME_MODE:-success}"
SIZE="${AGENTSHELL_FAKE_PNG_BYTES:-200}"

# Record how we were launched, and how private the profile we were handed is.
if [ -n "${AGENTSHELL_FAKE_CHROME_ARGV:-}" ]; then
  printf 'ARGV-COUNT %s\n' "$#" > "$AGENTSHELL_FAKE_CHROME_ARGV"
  for a in "$@"; do printf '%s\n' "$a" >> "$AGENTSHELL_FAKE_CHROME_ARGV"; done
fi
if [ -n "${AGENTSHELL_FAKE_CHROME_ENV:-}" ]; then
  {
    printf 'HOME=%s\n' "${HOME:-<unset>}"
    printf 'XDG_CONFIG_HOME=%s\n' "${XDG_CONFIG_HOME:-<unset>}"
    printf 'XDG_CACHE_HOME=%s\n' "${XDG_CACHE_HOME:-<unset>}"
    printf 'XDG_DATA_HOME=%s\n' "${XDG_DATA_HOME:-<unset>}"
    printf 'PROFILE=%s\n' "${PROFILE:-<none>}"
  } > "$AGENTSHELL_FAKE_CHROME_ENV"
fi
if [ -n "${AGENTSHELL_FAKE_CHROME_PROFILE_MODE:-}" ] && [ -n "$PROFILE" ]; then
  stat -c '%a' "$PROFILE" > "${AGENTSHELL_FAKE_CHROME_ARGV}.profilemode" 2>/dev/null
fi

# Behave like a browser that keeps what it rendered: write a cache entry into
# the profile it was given. If isolation works, this dies with the profile.
if [ -n "${AGENTSHELL_FAKE_CHROME_CACHE:-}" ] && [ -n "$PROFILE" ]; then
  mkdir -p "$PROFILE/Default/Cache" 2>/dev/null
  printf '%s' "${AGENTSHELL_FAKE_CHROME_CACHE}" > "$PROFILE/Default/Cache/entry.bin" 2>/dev/null
fi

# 8-byte PNG magic + body: enough for magic validation and >100 bytes.
write_png() {
  printf '\211PNG\r\n\032\n' > "$OUT"
  head -c "$SIZE" /dev/zero | tr '\0' 'X' >> "$OUT"
}
case "$MODE" in
  hang)
    write_png
    # A grandchild that would outlive us if only the direct child were killed.
    sleep 300 &
    echo "$!" > "${OUT}.gc"
    wait
    ;;
  fail)     exit 3 ;;
  garbage)  printf 'NOT-A-PNG-AT-ALL' > "$OUT"; head -c "$SIZE" /dev/zero | tr '\0' 'X' >> "$OUT" ;;
  truncate) printf '\211PNG\r\n\032\n' > "$OUT"; head -c 20 /dev/zero | tr '\0' 'X' >> "$OUT" ;;
  *)        write_png ;;
esac
SH;
            file_put_contents( $path, $script );
            chmod( $path, 0755 );
        }
        return $path;
    }
}