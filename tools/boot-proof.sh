#!/usr/bin/env bash
# boot-proof.sh — a tiny app boots, and offers only what it can actually do.
#
#   tools/boot-proof.sh          # run from the app root, after `composer install`
#
# ── WHY THIS IS A SCRIPT AND NOT CI YAML ────────────────────────────────────────────────────────
#
# It used to be eight lines inlined in `.github/workflows/ci.yml`, which meant it could only ever run
# in one place: after the code was already pushed. On 2026-08-04 that is exactly what happened —
# `milpa/app-runtime` v0.3.0 turned three optional packages into hard requirements, a tiny install
# started pulling `milpa/agent` transitively, and the app began offering `agent:sessions` to owners
# who had never installed it. The release ceremony passed all ten of its gates and published anyway.
# This proof caught it, one minute too late to matter.
#
# A gate that only runs downstream reports history. As a script it runs in both places from one
# source: this repo's CI, and the release ceremony in the exported tree before it pushes.
#
# ── WHAT IT PROVES, AND WHAT IT DOESN'T ─────────────────────────────────────────────────────────
#
# It proves the CUT is real: that what a newcomer receives boots, does not announce work it cannot
# do, and says how to grow. It does not prove any of those operations work well — other suites do
# that. This one guards the promise that is easiest to break by accident and hardest to notice: a
# manifest edit three packages away can undo it without touching a line of this app.
set -euo pipefail

RAIZ="${1:-.}"
cd "$RAIZ"

SALIDA=$(php bin/coa list 2>&1)

# 1 · EL CATÁLOGO SIEMPRE. Es la única operación que una app tiny siempre tiene: si dependiera de un
# paquete, la app más pequeña —la que más necesita que le enseñen el camino— sería justo la que no lo
# tendría (ADR-0040).
grep -q 'capabilities' <<<"$SALIDA" || { echo "el catálogo no se ofrece"; exit 1; }

# 2 · Y NADA QUE NO PUEDA CUMPLIR. Cada una de estas necesita un paquete que su dueño no instaló.
for op in 'agent:sessions' 'token:list' 'coa chat'; do
  if grep -q "$op" <<<"$SALIDA"; then
    echo "ofrece $op sin su paquete"
    exit 1
  fi
done

# 3 · Y DICE CÓMO CRECER. Sin esto, el corte es honesto y además inútil: la app calla lo que no puede
# hacer y tampoco dice qué instalar para poder.
php bin/coa capabilities 2>&1 | grep -q 'composer require milpa/agent' \
  || { echo "no enseña el camino"; exit 1; }

echo "tiny ok: arranca, no promete lo que no puede, y dice cómo crecer"
