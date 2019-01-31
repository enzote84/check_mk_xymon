#!/bin/bash
#
# Check Xymon hosts and services for check_mk
#
# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
#
# Version: 1.0
# Author: DEMR
#
# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
# Plugin info
#
AUTHOR="DEMR"
VERSION="1.0"
PROGNAME=$(basename $0)

print_version() {
  echo ""
  echo "Version: $VERSION, Author: $AUTHOR"
  echo ""
}

print_usage() {
  echo ""
  echo "This script checks for hosts and services of a Xymon server."
  echo ""
  echo "$PROGNAME"
  echo "Version: $VERSION"
  echo ""
  echo "Usage: $PROGNAME [-v | -h]"
  echo ""
  echo "  -h  Show this page"
  echo "  -v  Plugin Version"
  echo ""
}

# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
# Return codes
#
STATE_OK=0
STATE_WARNING=1
STATE_CRITICAL=2
STATE_UNKNOWN=3

# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
# Auxiliar functions
#

# Return a state number corresponding to a colour from Xymon
color_to_code() {
  case $1 in
    green)
	  CODE=$STATE_OK
	  ;;
	yellow)
	  CODE=$STATE_WARNING
	  ;;
	red)
	  CODE=$STATE_CRITICAL
	  ;;
	*)
	  CODE=$STATE_UNKNOWN
	  ;;
  esac
  echo "$CODE"
}

# Read the last value registered in an RRD file
rrd_last_value() {
  # Check if file exist
  RRDFILE="$1"
  if [[ -f $RRDFILE ]]; then
    echo "ERROR: File $RRDFILE not found." >&2
    return $STATE_UNKNOWN
  fi
  # Get last update time
  LASTUPDATE=$(rrdtool last $RRDFILE)
  if [[ ! $? ]]; then
    echo "ERROR: Couldn't get last update time." >&2
    return $STATE_UNKNOWN
  fi
  # Get last value
  LASTVALUE=$(rrdtool fetch $RRDFILE AVERAGE|awk -F': ' -v last=$LASTUPDATE 'BEGIN{val=last;} {if($1<last) val=$2;} END{print val;}')
  if [[ ! $? ]]; then
    echo "ERROR: Couldn't get last value." >&2
    return $STATE_UNKNOWN
  fi
  echo "$LASTVALUE"
}

# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
# Xymon configuration
#
XYMONHOST="127.0.0.1"
QUERYBOARD="xymondboard fields=hostname,testname,color,line1"
XYMONHOME=$(ps ax|awk '{print $5;}' | grep "^.*/server/bin/xymonlaunch.*" | sed "s/\/server\/bin\/xymonlaunch//g")
#XYMONHOSTS=$(grep "^[0-9a-zA-Z].*" $XYMONHOME/server/etc/hosts.cfg | awk '{print $2;}')
RRDHOME="$XYMONHOME/data/rrd"

# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
# Xymon checks
#

# Query Xymon for last checks of each service:
XYMONBOARD=$($XYMONHOME/server/bin/xymon $XYMONHOST "$QUERYBOARD" | sed "s/<\!\-\-.*\-\->//g")
if [[ ! $? ]]; then
  echo "ERROR: Could check for Xymon services. Is xymon working?" >&2
  exit $STATE_UNKNOWN
fi
# Values in board are separated by '|' (pipes).
# Each line has: hostname|testname|color|line1.
echo "$XYMONBOARD" | while read check; do
  host=$(echo $check | awk -F'|' '{print $1;}')
  testname=$(echo $check | awk -F'|' '{print $2;}')
  color=$(echo $check | awk -F'|' '{print $3;}')
  line1=$(echo $check | awk -F'|' '{print $4;}')
  status=$(color_to_code "$color")
  if [[ -z $line1 ]]; then
    line1="$color"
  fi
  echo "$status ${host}_${testname} status=$status $line1"
done

