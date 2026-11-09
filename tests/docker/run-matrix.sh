#!/usr/bin/env bash
set -euo pipefail
script_directory=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
repository_directory=$(cd -- "$script_directory/../.." && pwd)
if [ "${1:-}" = --all ]; then
    lane_ids=$(python3 - "$script_directory/matrix.json" <<'PY'
import json, sys
lanes = json.load(open(sys.argv[1]))['include']
if not lanes:
    raise SystemExit('The matrix must contain at least one lane')
for lane in lanes:
    print(lane['id'])
PY
)
    while IFS= read -r selected_lane; do
        bash "$0" "$selected_lane"
    done <<< "$lane_ids"
    exit 0
fi
if [ "${1:-}" = --list ]; then
    python3 - "$script_directory/matrix.json" <<'PY'
import json, sys
for lane in json.load(open(sys.argv[1]))['include']:
    print(f"{lane['id']}: PHP {lane['php']}, Swoole {lane['swoole']}, MySQL {lane['mysql']}, {lane['tls']}")
PY
    exit 0
fi
if [ "$#" -ne 1 ]; then
    echo "Usage: $0 LANE_ID | --all | --list" >&2
    exit 2
fi
lane_id=$1
# 解析本地 JSON 记录，避免将矩阵字段作为 Shell 代码执行。
lane_values=$(python3 - "$script_directory/matrix.json" "$lane_id" <<'PY'
import json, re, sys
lanes = json.load(open(sys.argv[1]))['include']
try:
    lane = next(item for item in lanes if item['id'] == sys.argv[2])
except StopIteration:
    raise SystemExit('Unknown matrix lane: ' + sys.argv[2])
for key in ('php', 'swoole', 'mysql', 'tls'):
    value = lane[key]
    if not isinstance(value, str) or not re.fullmatch(r'[a-zA-Z0-9_.-]+', value):
        raise SystemExit('Invalid matrix field: ' + key)
    print(value)
print('1' if lane['unit'] else '0')
PY
)
php_image_tag=$(printf '%s\n' "$lane_values" | sed -n '1p')
swoole_version=$(printf '%s\n' "$lane_values" | sed -n '2p')
mysql_version=$(printf '%s\n' "$lane_values" | sed -n '3p')
tls_mode=$(printf '%s\n' "$lane_values" | sed -n '4p')
run_unit=$(printf '%s\n' "$lane_values" | sed -n '5p')
for dependency in docker python3 openssl; do
    if ! command -v "$dependency" >/dev/null 2>&1; then
        echo "Missing $dependency; install/enable a container runtime before running this lane." >&2
        exit 2
    fi
done
docker info >/dev/null
results_directory="$script_directory/results/$lane_id"
mkdir -p "$results_directory"
# 即使本地并发运行多次，各次创建的资源名称也保持唯一。
run_id="mysqli-ci-$(date +%s)-$$"
network_name="$run_id-net"
database_container="$run_id-mysql"
test_container="$run_id-php"
certificate_volume="$run_id-certs"
data_volume="$run_id-data"
certificate_directory=$(mktemp -d "${TMPDIR:-/tmp}/mysqli-ci-certs.XXXXXX")
cleanup() {
    exit_status=$?
    trap - EXIT INT TERM
    docker logs "$database_container" > "$results_directory/mysql.log" 2>&1 || true
    docker rm -f "$test_container" "$database_container" >/dev/null 2>&1 || true
    docker volume rm "$certificate_volume" "$data_volume" >/dev/null 2>&1 || true
    docker network rm "$network_name" >/dev/null 2>&1 || true
    rm -rf -- "$certificate_directory"
    exit "$exit_status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
runtime_image="mysqli-test-runtime:php-${php_image_tag}-swoole-${swoole_version}"
mysql_image="mysql:${mysql_version}"
bash "$script_directory/generate-certificates.sh" "$certificate_directory"
docker build --build-arg "PHP_IMAGE=php:$php_image_tag" --build-arg "SWOOLE_VERSION=$swoole_version" \
    --tag "$runtime_image" "$script_directory" 2>&1 | tee "$results_directory/build.log"
docker pull "$mysql_image" 2>&1 | tee "$results_directory/pull.log"
docker image inspect "$runtime_image" "$mysql_image" \
    --format '{{.Id}} {{json .RepoDigests}} {{.Os}}/{{.Architecture}}' > "$results_directory/images.txt"
docker network create --label "mysqli.test.run=$run_id" "$network_name" >/dev/null
docker volume create --label "mysqli.test.run=$run_id" "$certificate_volume" >/dev/null
docker volume create --label "mysqli.test.run=$run_id" "$data_volume" >/dev/null
# 在当前服务端镜像中查询 mysql 用户的 UID，不假定其为 999。
# 此临时卷仅包含服务端私钥、证书和 CA 证书。
docker run --rm --user 0 --entrypoint bash \
    --mount "type=bind,src=$certificate_directory,dst=/input,readonly" \
    --mount "type=volume,src=$certificate_volume,dst=/certs" "$mysql_image" -ec \
    'cp /input/ca.pem /input/server-cert.pem /input/server-key.pem /certs/; chown -R mysql:mysql /certs; chmod 700 /certs; chmod 600 /certs/server-key.pem; chmod 644 /certs/ca.pem /certs/server-cert.pem'
secure_transport=ON
if [ "$tls_mode" = disabled ]; then secure_transport=OFF; fi
# 这些测试凭据仅用于未向外暴露的一次性 Docker 网络。
ci_root_password=container_test_root_password
ci_password=container_test_password
docker run -d --name "$database_container" --network "$network_name" --network-alias mysql \
    --label "mysqli.test.run=$run_id" \
    --env "MYSQL_ROOT_PASSWORD=$ci_root_password" --env MYSQL_DATABASE=mysqli_ci \
    --env MYSQL_USER=mysqli_ci --env "MYSQL_PASSWORD=$ci_password" \
    --mount "type=volume,src=$certificate_volume,dst=/certs,readonly" \
    --mount "type=volume,src=$data_volume,dst=/var/lib/mysql" "$mysql_image" \
    --ssl-ca=/certs/ca.pem --ssl-cert=/certs/server-cert.pem --ssl-key=/certs/server-key.pem \
    --require-secure-transport="$secure_transport" --tls-version=TLSv1.2,TLSv1.3 \
    --max-allowed-packet=1073741824 \
    --sql-mode=STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION >/dev/null
ready=0
for attempt in {1..120}; do
    if docker exec --env "MYSQL_PWD=$ci_password" "$database_container" \
        mysql --protocol=TCP --host=127.0.0.1 --user=mysqli_ci --database=mysqli_ci --ssl-mode=REQUIRED --batch --skip-column-names \
        --execute='SELECT 1' > /dev/null 2> "$results_directory/readiness.log"; then
        ready=1
        break
    fi
    if [ "$(docker inspect --format '{{.State.Running}}' "$database_container")" != true ]; then break; fi
    sleep 1
done
if [ "$ready" != 1 ]; then
    echo "Disposable MySQL did not become ready; see $results_directory/mysql.log" >&2
    exit 1
fi
docker run --rm --name "$test_container" --network "$network_name" --label "mysqli.test.run=$run_id" \
    --mount "type=bind,src=$repository_directory,dst=/source,readonly" \
    --mount "type=bind,src=$results_directory,dst=/results" \
    --mount "type=volume,src=$certificate_volume,dst=/certs,readonly" \
    --env MYSQLI_TEST_HOST=mysql --env MYSQLI_TEST_PORT=3306 --env MYSQLI_TEST_USER=mysqli_ci \
    --env "MYSQLI_TEST_PASSWORD=$ci_password" --env MYSQLI_TEST_DATABASE=mysqli_ci \
    --env "MYSQLI_TEST_TLS_MODE=$tls_mode" --env MYSQLI_TEST_TLS_CA_FILE=/certs/ca.pem \
    --env MYSQLI_TEST_TLS_SERVER_NAME=mysql --env "MYSQLI_MATRIX_TLS_MODE=$tls_mode" \
    --env "MYSQLI_EXPECT_MYSQL_VERSION=$mysql_version" --env "MYSQLI_EXPECT_PHP_VERSION=${php_image_tag%%-*}" \
    --env "MYSQLI_EXPECT_SWOOLE_VERSION=$swoole_version" --env "MYSQLI_MATRIX_RUN_UNIT=$run_unit" \
    "$runtime_image" 2>&1 | tee "$results_directory/tests.log"
