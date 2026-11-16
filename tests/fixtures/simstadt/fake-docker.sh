#!/bin/bash
# Stands in for `docker run … simstadt/simstadt:cli simstadt <Template> <gml> --project-path … --save /data/<x>.csv`
# in tests: writes the fixture table for the template and prints a summary like simstadtpy.
HERE=$(cd "$(dirname "$0")" && pwd)
while [[ $# -gt 0 ]]; do
  case "$1" in
    -v) MOUNT="${2%%:*}"; shift 2 ;;
    --save) SAVE="${2#/data/}"; shift 2 ;;
    simstadt) TEMPLATE="$2"; INPUT="${3#/data/}"; shift 3 ;;
    *) shift ;;
  esac
done
[[ -s "$MOUNT/$INPUT" ]] || { echo "no input $INPUT" >&2; exit 2; }
case "$TEMPLATE" in
  PV) cp "$HERE/pv_potential.csv" "$MOUNT/$SAVE"; printf 'PV for site.gml\n\n  Roof area for PV                    : 268 m²\n  PV potential nominal power          : 47 kWp\n' ;;
  *) cp "$HERE/heat_demand.csv" "$MOUNT/$SAVE"; printf '%s for site.gml\n\n  Number of buildings                 : 2\n  Yearly Heating demand               : 376000 kWh / a\n\nOutput files:\n  /data/x.csv\n' "$TEMPLATE" ;;
esac
