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
 *   AGENTSHELL_FAKE_CHROME_SCRATCH   create this directory under TMPDIR, the
 *                                    way Chromium creates its own scratch
 *                                    directories while rendering, and record
 *                                    the absolute path it used
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
WIN_W=0
WIN_H=0
for a in "$@"; do
  case "$a" in
    --screenshot=*) OUT="${a#--screenshot=}" ;;
    --user-data-dir=*) PROFILE="${a#--user-data-dir=}" ;;
    --window-size=*)
      WIN_W="${a#--window-size=}"
      WIN_H="${WIN_W#*,}"
      WIN_W="${WIN_W%%,*}"
      ;;
  esac
done

# Headless Chrome enforces a minimum window width (around 485 CSS pixels
# on the hosts this was measured on), so a narrow window is laid out
# wider than it was asked for and the screenshot is a crop of that wider
# layout. The fake reproduces that, so a test can tell whether the
# capture code reports the image it got or the size it asked for.
CHROME_MIN_WIDTH=485
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
    printf 'TMPDIR=%s\n' "${TMPDIR:-<unset>}"
    printf 'PROFILE=%s\n' "${PROFILE:-<none>}"
  } > "$AGENTSHELL_FAKE_CHROME_ENV"
fi

# Behave like Chromium's URLFetcher scratch directory: one per capture, created
# in TMPDIR and otherwise left behind. Whether it survives is the property the
# trust suite cares about — it must not outlive the profile.
if [ -n "${AGENTSHELL_FAKE_CHROME_SCRATCH:-}" ] && [ -n "${TMPDIR:-}" ]; then
  SCRATCH="${TMPDIR}/${AGENTSHELL_FAKE_CHROME_SCRATCH}"
  mkdir -p "$SCRATCH" 2>/dev/null
  printf 'rendered' > "$SCRATCH/payload.bin" 2>/dev/null
  printf '%s' "$SCRATCH" > "${AGENTSHELL_FAKE_CHROME_ENV}.scratch" 2>/dev/null
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
#
# AGENTSHELL_FAKE_PNG_SIZE=WxH writes a PNG whose IHDR chunk carries those
# dimensions, so a test can tell what the capture code reports about an
# image that is not the size it asked the browser for. A real browser clamps
# a narrow window to its minimum width, and the capture code must report
# the image it actually got.
write_png_sized() {
  local W="$1" H="$2"
  if [ "$W" -lt "$CHROME_MIN_WIDTH" ] 2>/dev/null; then W="$CHROME_MIN_WIDTH"; fi
  printf '\211PNG\r\n\032\n\000\000\000\015IHDR' > "$OUT"
  # Each byte gets its own \xNN escape: bash's printf reads at most two
  # hex digits after \x, so one escape for four bytes would write the
  # wrong number.
  printf "\x$(printf '%02X' $(( (W >> 24) & 255 )))\x$(printf '%02X' $(( (W >> 16) & 255 )))\x$(printf '%02X' $(( (W >> 8) & 255 )))\x$(printf '%02X' $(( W & 255 )))" >> "$OUT"
  printf "\x$(printf '%02X' $(( (H >> 24) & 255 )))\x$(printf '%02X' $(( (H >> 16) & 255 )))\x$(printf '%02X' $(( (H >> 8) & 255 )))\x$(printf '%02X' $(( H & 255 )))" >> "$OUT"
  # Keep the total size exactly what the size knob asked for.
  local body=$(( SIZE - 16 ))
  if [ "$body" -gt 0 ] 2>/dev/null; then head -c "$body" /dev/zero | tr '\0' 'X' >> "$OUT"; fi
}

write_png() {
  if [ -n "${AGENTSHELL_FAKE_PNG_SIZE:-}" ]; then
    write_png_sized "${AGENTSHELL_FAKE_PNG_SIZE%x*}" "${AGENTSHELL_FAKE_PNG_SIZE#*x}"
    return
  fi
  if [ "$WIN_W" -gt 0 ] 2>/dev/null; then
    write_png_sized "$WIN_W" "$WIN_H"
    return
  fi
  printf '\211PNG\r\n\032\n' > "$OUT"
  head -c "$SIZE" /dev/zero | tr '\0' 'X' >> "$OUT"
}
# The browser may be launched without an output path — the DevTools path
# asks the browser to open a page, not to write a file. Writing to an empty
# path would litter the working directory with a file called ".gc".
if [ -z "$OUT" ]; then
  case "$MODE" in
    hang) sleep 300 & wait ;;
    fail) exit 3 ;;
    *)    exit 0 ;;
  esac
  exit $?
fi

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