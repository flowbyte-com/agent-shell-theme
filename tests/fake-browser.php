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
 *
 * The size knob exists for the inline size-limit tests, which need a capture
 * above and below the limit without shipping a multi-megabyte fixture.
 */

if ( ! function_exists( 'agentshell_fake_browser_path' ) ) {
    function agentshell_fake_browser_path( $dir ) {
        $path = $dir . '/fake-browser.sh';
        if ( ! file_exists( $path ) ) {
            $script = <<<'SH'
#!/bin/bash
# Fake headless browser. Parses --screenshot=<path>, behaves per mode.
OUT=""
for a in "$@"; do
  case "$a" in
    --screenshot=*) OUT="${a#--screenshot=}" ;;
  esac
done
MODE="${AGENTSHELL_FAKE_CHROME_MODE:-success}"
SIZE="${AGENTSHELL_FAKE_PNG_BYTES:-200}"
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