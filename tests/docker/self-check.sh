#!/usr/bin/env bash
set -euo pipefail
script_directory=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
repository_directory=$(cd -- "$script_directory/../.." && pwd)
for source in "$script_directory"/*.sh; do bash -n "$source"; done
python3 - "$script_directory" <<'PY'
import json, pathlib, re, sys
root = pathlib.Path(sys.argv[1])
lanes = json.loads((root / 'matrix.json').read_text())['include']
assert len(lanes) == 4
assert len({lane['id'] for lane in lanes}) == len(lanes)
assert {lane['mysql'].rsplit('.', 1)[0] for lane in lanes} == {'8.0', '8.4', '9.7'}
assert {lane['tls'] for lane in lanes} == {'disabled', 'verify_identity'}
assert len({(lane['php'], lane['swoole']) for lane in lanes if lane['unit']}) == 2
for lane in lanes:
    assert re.fullmatch(r'[a-z0-9-]+', lane['id'])
    assert re.fullmatch(r'8\.(1|4)\.\d+-cli-bookworm', lane['php'])
    assert re.fullmatch(r'(5\.1|6\.2)\.\d+', lane['swoole'])
    assert re.fullmatch(r'(8\.0|8\.4|9\.7)\.\d+', lane['mysql'])
    assert isinstance(lane['unit'], bool)
    assert (lane['php'].startswith('8.1.') and lane['swoole'].startswith('5.1.')) or (lane['php'].startswith('8.4.') and lane['swoole'].startswith('6.2.'))
lock = json.loads((root / 'dependencies.lock.json').read_text())
assert re.fullmatch(r'[0-9a-f]{32}', lock['content-hash'])
assert any(package['name'] == 'phpunit/phpunit' for package in lock['packages-dev'])
print('Matrix and dependency snapshot JSON passed')
PY
if command -v ruby >/dev/null 2>&1; then
    ruby -rpsych -e 'document = Psych.safe_load(File.read(ARGV[0]), aliases: false); abort "Missing workflow jobs" unless document.fetch("jobs").keys.sort == ["database", "plan"]; puts "Workflow YAML passed"' \
        "$repository_directory/.github/workflows/tests.yml"
else
    echo 'Ruby/Psych unavailable; workflow YAML parser check skipped (CI plan runs it on Ubuntu).'
fi
if command -v php >/dev/null 2>&1; then
    php -l "$script_directory/probe.php"
    php "$script_directory/probe.php" --self-test
else
    echo 'Host PHP unavailable; PHP checks run inside the test container.'
fi
certificate_directory=$(mktemp -d "${TMPDIR:-/tmp}/mysqli-cert-selfcheck.XXXXXX")
trap 'rm -rf -- "$certificate_directory"' EXIT
bash "$script_directory/generate-certificates.sh" "$certificate_directory"
if bash "$script_directory/generate-certificates.sh" "$certificate_directory" >/dev/null 2>&1; then
    echo 'Certificate generator unexpectedly accepted a nonempty directory' >&2
    exit 1
fi
bash "$script_directory/run-matrix.sh" --list
if bash "$script_directory/run-matrix.sh" nonexistent-lane >/dev/null 2>&1; then
    echo 'Unknown matrix lane was not rejected' >&2
    exit 1
fi
echo 'Harness self-check passed; this does not execute the container matrix.'
