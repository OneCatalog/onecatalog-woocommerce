#!/usr/bin/env bash
# Собрать архив WP-плагина: onecatalog-import-<ver>.zip (папка onecatalog-import/ внутри).
set -euo pipefail
cd "$(dirname "$0")"
ver=$(grep -oE 'Version: *[0-9.]+' onecatalog-import.php | head -1 | grep -oE '[0-9.]+')
out="onecatalog-import-${ver:-dev}.zip"
rm -f "$out"
git archive --format=zip --prefix=onecatalog-import/ -o "$out" HEAD
echo "✔ $out"
