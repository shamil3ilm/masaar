#!/usr/bin/env bash
#
# Run a conformance suite and fail unless it truly ran.
#
# Usage: scripts/ci/run-conformance.sh <filter> <TestClass> [<TestClass> ...]
#
# The conformance jobs once piped `php artisan test` through `tee`. Without
# pipefail the pipeline's status is tee's, so when the application refused
# to boot - no .env, which leaves ZATCA on its sandbox default, which the
# platform rightly refuses in production - not one test ran and the job
# still went green. Judging the console's "skipped" line could not catch it:
# a run that never starts prints no summary at all.
#
# So the verdict comes from the JUnit report instead. The job fails when the
# run fails, when the report is missing or empty, when anything skipped,
# and when a named class did not run. Each failure is also printed as a
# workflow annotation, readable without the job log.

set -uo pipefail

filter="${1:?filter required}"
shift

report="$(mktemp --suffix=.xml)"
log="$(mktemp)"

status=0
php artisan test --do-not-cache-result --log-junit "$report" --filter "$filter" >"$log" 2>&1 || status=$?
cat "$log"

annotate() {
    # Workflow commands are one line; %0A is a newline inside the message.
    printf '::error title=%s::%s\n' "$1" "$(printf '%s' "$2" | sed 's/\x1b\[[0-9;]*m//g' | awk 'BEGIN{ORS="%0A"} {print}')"
}

if [ ! -s "$report" ]; then
    annotate "Suite did not run" "$(tail -n 40 "$log")"
    exit 1
fi

php -r '
    $xml = simplexml_load_file($argv[1]);
    foreach ($xml->xpath("//testcase[failure or error]") as $case) {
        $node = $case->failure ?: $case->error;
        $first = strtok(trim((string) $node), "\n");
        echo "::error title=".$case["class"]."::".$case["name"].": ".substr((string) $first, 0, 400)."\n";
    }
' "$report"

if [ "$status" -ne 0 ]; then
    annotate "Suite failed ($status)" "$(tail -n 40 "$log")"
    exit "$status"
fi

tests="$(php -r '$x = simplexml_load_file($argv[1]); echo (int) ($x->testsuite["tests"] ?? 0);' "$report")"
if [ "$tests" -eq 0 ]; then
    annotate "No tests ran" "The filter '$filter' matched nothing."
    exit 1
fi

if grep -q '<skipped' "$report"; then
    annotate "Suite skipped" "The suite's tools were installed but tests still skipped."
    exit 1
fi

for class in "$@"; do
    if ! grep -q "$class" "$report"; then
        annotate "Suite missing" "$class did not run; the filter no longer matches it."
        exit 1
    fi
done

echo "$tests tests ran; none skipped."
