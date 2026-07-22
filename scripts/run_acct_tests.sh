#!/usr/bin/env bash
# 회계 대사 테스트 실행. 개발 MySQL(.devdb) 가동 상태여야 함.
set -e
cd "$(dirname "$0")/.."
php scripts/tests/run.php
