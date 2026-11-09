#!/usr/bin/env bash
set -euo pipefail
if [ "$#" -ne 1 ]; then
    echo "Usage: $0 EMPTY_OUTPUT_DIRECTORY" >&2
    exit 2
fi
certificate_directory=$1
umask 077
mkdir -p "$certificate_directory"
if [ -n "$(ls -A "$certificate_directory")" ]; then
    echo "Certificate output directory must be empty" >&2
    exit 2
fi
openssl req -new -newkey rsa:2048 -nodes -x509 -sha256 -days 2 \
    -subj '/CN=mysqli-container-test-ca' \
    -keyout "$certificate_directory/ca-key.pem" -out "$certificate_directory/ca.pem" >/dev/null 2>&1
openssl req -new -newkey rsa:2048 -nodes -sha256 -subj '/CN=mysql' \
    -keyout "$certificate_directory/server-key.pem" -out "$certificate_directory/server.csr" >/dev/null 2>&1
cat > "$certificate_directory/server.ext" <<'EXT'
basicConstraints=critical,CA:FALSE
keyUsage=critical,digitalSignature,keyEncipherment
extendedKeyUsage=serverAuth
subjectAltName=DNS:mysql,DNS:localhost,IP:127.0.0.1
EXT
openssl x509 -req -sha256 -days 2 -in "$certificate_directory/server.csr" \
    -CA "$certificate_directory/ca.pem" -CAkey "$certificate_directory/ca-key.pem" -CAcreateserial \
    -extfile "$certificate_directory/server.ext" -out "$certificate_directory/server-cert.pem" >/dev/null 2>&1
openssl verify -CAfile "$certificate_directory/ca.pem" "$certificate_directory/server-cert.pem"
openssl x509 -in "$certificate_directory/server-cert.pem" -noout -checkhost mysql
rm "$certificate_directory/server.csr" "$certificate_directory/server.ext" "$certificate_directory/ca.srl"
