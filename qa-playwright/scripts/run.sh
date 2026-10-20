#!/usr/bin/env bash
set -uo pipefail
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

mode="${1:-smoke}"
case "$mode" in
  smoke) args=(
    --project=chrome-dev
    tests/calculator/common/navigation.spec.ts
    tests/calculator/transport/form-smoke.spec.ts
    tests/calculator/energy/form-smoke.spec.ts
    tests/calculator/water/form-smoke.spec.ts
    tests/calculator/accommodation/form-smoke.spec.ts
    tests/calculator/catering/form-smoke.spec.ts
    tests/calculator/waste/form-smoke.spec.ts
    tests/calculator/materials/form-smoke.spec.ts
  ) ;;
  calculator) args=(--project=chrome-dev tests/calculator) ;;
  transport) args=(--project=chrome-dev tests/calculator/transport) ;;
  materials) args=(--project=chrome-dev tests/calculator/materials) ;;
  p2)
    export QA74_RUN_ID="${QA74_RUN_ID:-QA74-$(date -u +%Y%m%dT%H%M%SZ)-$$}"
    args=(--project=chrome-dev tests/persistence)
    ;;
  failed) args=(--project=chrome-dev --last-failed) ;;
  *) echo "Modo no admitido: $mode" >&2; exit 2 ;;
esac

if [[ ! -f qa.local.json ]]; then
  echo 'Falta qa-playwright/qa.local.json. Copia qa.local.example.json y rellena los datos DEV.' >&2
  exit 2
fi
if [[ ! -x node_modules/.bin/playwright ]]; then
  echo 'Playwright no instalado. Ejecuta: cd /home/fede/www/begreenmyfriend/qa-playwright && npm install' >&2
  exit 2
fi
mkdir -p artifacts/logs
stamp="$(date +%Y%m%dT%H%M%S)"
log="artifacts/logs/${stamp}_${mode}.txt"
{
  echo "FECHA=$(date -Iseconds)"
  echo "MODO=$mode"
  echo "ENTORNO=https://begreen.tech-investments.net"
  echo "COMMIT=$(git -C .. rev-parse --short HEAD 2>/dev/null || echo 'no-disponible')"
  if [[ "$mode" == "p2" ]]; then echo "RUN_ID=$QA74_RUN_ID"; fi
  echo 'SIN GUARDADO DE CREDENCIALES EN LOG POR EL RUNNER'
} > "$log"
# El formulario real de login nunca se imprime. Playwright puede mostrar URLs de red
# en trazas de fallos; evitar compartir artefactos sin revisarlos previamente.
./node_modules/.bin/playwright test "${args[@]}" >>"$log" 2>&1
result=$?
tail -n 24 "$log"
echo "QA_EXIT_CODE=$result"
echo "LOG=$ROOT/$log"
echo "REPORT=$ROOT/artifacts/html-report/index.html"
echo "JSON=$ROOT/artifacts/results.json"
find artifacts/logs -type f -name '*.txt' -mtime +14 -delete 2>/dev/null || true
exit "$result"
