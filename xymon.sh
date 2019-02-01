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
# Return codes
#
STATE_OK=0
STATE_WARNING=1
STATE_CRITICAL=2
STATE_UNKNOWN=3

# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
# Info
#
AUTHOR="DEMR"
VERSION="1.0"
PROGNAME=$(basename $0)

print_version() {
  echo ""
  echo "Version: ${VERSION}, Author: ${AUTHOR}"
  echo ""
}

print_usage() {
  echo ""
  echo "This script checks for hosts and services of a Xymon server."
  echo ""
  echo "${PROGNAME}"
  echo "Version: ${VERSION}"
  echo ""
  echo "Usage: ${PROGNAME} [-v | -h]"
  echo ""
  echo "  -h  Show this page"
  echo "  -v  Software version"
  echo ""
}

# Parse parameters
while [ $# -gt 0 ]; do
  case "$1" in
    -h)
      print_usage
      exit $STATE_OK
      ;;
    -v)
      print_version
      exit $STATE_OK
      ;;
    *)
      echo "Unknown argument: $1"
      print_usage
      exit $STATE_UNKNOWN
      ;;
  esac
  shift
done

# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
# Auxiliar functions
#

# Return a state number corresponding to a colour from Xymon:
# Usage: color_to_code <color>
color_to_code() {
  case $1 in
    green)
	  CODE="${STATE_OK}"
	  ;;
	yellow)
	  CODE="${STATE_WARNING}"
	  ;;
	red)
	  CODE="${STATE_CRITICAL}"
	  ;;
	*)
	  CODE="${STATE_UNKNOWN}"
	  ;;
  esac
  echo "${CODE}"
}

# Return a number that represent availability based on status:
# Usage: color_to_avail <color>
color_to_avail() {
  case $1 in
    green)
	  AVAIL="100"
	  ;;
	yellow)
	  AVAIL="80"
	  ;;
	red)
	  AVAIL="0"
	  ;;
	*)
	  AVAIL="50"
	  ;;
  esac
  echo "${AVAIL}"
}

# Read the last value registered in an RRD file:
# Usage: rrd_last_value <rrd_file>
rrd_last_value() {
  # Check if file exist
  RRDFILE="$1"
  if [[ ! -f $RRDFILE ]]; then
    echo "ERROR: File $RRDFILE not found." >&2
    echo "0"
    return $STATE_UNKNOWN
  fi
  # Get last update time
  LASTUPDATE=$(rrdtool last $RRDFILE)
  if [[ ! $? ]]; then
    echo "ERROR: Couldn't get last update time." >&2
    echo "0"
    return $STATE_UNKNOWN
  fi
  # Get last value
  LASTVALUE=$(rrdtool fetch $RRDFILE AVERAGE|awk -F': ' -v last=$LASTUPDATE 'BEGIN{val=last;} {if($1<last) val=$2;} END{print val;}'|sed "s/ .*$//g")
  if [[ ! $? ]]; then
    echo "ERROR: Couldn't get last value." >&2
    echo "0"
    return $STATE_UNKNOWN
  else
    echo "$LASTVALUE"
  fi
}

# Get rrd files associated with a service:
# Usage: rrd_by_service <host> <service> <rrdhome>
# Return: a records list. Each record is: metric=rrdfile
rrd_by_service() {
  RRDS=""
  RRDHOST=$1
  RRDSERV=$2
  RRDHOME=$3
  THISRRDDIR="${RRDHOME}/${RRDHOST}"
  # In each case, check for file existance
  case $RRDSERV in
    trends)
      ;;
    clientlog)
      ;;
    info)
      ;;
    cpu)
      THISRRDFILE="${THISRRDDIR}/la.rrd"
      if [[ -f $THISRRDFILE ]]; then
        RRDS="cpu=${THISRRDFILE}##"
      fi
      ;;
    memory)
      THISRRDFILES=$(ls ${THISRRDDIR}/memory*.rrd)
      for file in $THISRRDFILES; do
        THISMETRIC=$(echo "${file}"|sed "s/^.*memory\.//g"|sed "s/.rrd$//g")
        RRDS="${RRDS}${THISMETRIC}=${file}##"
      done
      ;;
    disk)
      THISRRDFILES=$(ls ${THISRRDDIR}/disk*.rrd)
      for file in $THISRRDFILES; do
        THISMETRIC=$(echo "${file}"|sed "s/^.*disk,//g"|sed "s/.rrd$//g")
        RRDS="${RRDS}${THISMETRIC}=${file}##"
      done
      ;;
    inode)
      THISRRDFILES=$(ls ${THISRRDDIR}/inode*.rrd)
      for file in $THISRRDFILES; do
        THISMETRIC=$(echo "${file}"|sed "s/^.*inode,//g"|sed "s/.rrd$//g")
        RRDS="${RRDS}${THISMETRIC}=${file}##"
      done
      ;;
    bbd)
      THISRRDFILE="${THISRRDDIR}/tcp.bbd.rrd"
      if [[ -f $THISRRDFILE ]]; then
        RRDS="bbd=${THISRRDFILE}##"
      fi
      ;;
    conn)
      THISRRDFILE="${THISRRDDIR}/tcp.conn.rrd"
      if [[ -f $THISRRDFILE ]]; then
        RRDS="conn=${THISRRDFILE}##"
      fi
      ;;
    http)
      THISRRDFILES=$(ls ${THISRRDDIR}/tcp.http.*.rrd)
      for file in $THISRRDFILES; do
        THISMETRIC=$(echo "${file}"|sed "s/^.*tcp\.//g"|sed "s/.rrd$//g"|sed "s/\.|,//g")
        RRDS="${RRDS}${THISMETRIC}=${file}##"
      done
      ;;
    *)
      THISRRDFILE="${THISRRDDIR}/$RRDSERV.rrd"
      if [[ -f $THISRRDFILE ]]; then
        RRDS="${RRDSERV}=${THISRRDFILE}##"
      fi
      ;;
  esac
  # Return findings:
  echo "${RRDS}"
}

# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
# Xymon configuration
#
#XYMSRV="127.0.0.1"
# Try to find where is Xymon installed:
XYMONSERVERROOT=$(ps ax|awk '{print $5;}' | grep "^.*/server/bin/xymonlaunch.*" | sed "s/\/server\/bin\/xymonlaunch//g")
if [[ -z $XYMONSERVERROOT ]]; then
  echo "ERROR: Couldn't check for Xymon services. Is xymon working?" >&2
  exit $STATE_UNKNOWN
fi
# It is better to use Xymon environment variables:
MAINCFGFILE="$XYMONSERVERROOT/server/etc/xymonserver.cfg"
. $MAINCFGFILE
if [[ ! $? ]]; then
  echo "ERROR: Couldn't check environment variables: $MAINCFGFILE." >&2
  exit $STATE_UNKNOWN
fi

#RRDHOME="$XYMONSERVERROOT/data/rrd"
#XYMONRRDS this is defined in MAINCFGFILE, is equal to RRDHOME
#XYMONHOSTS=$(grep "^[0-9a-zA-Z].*" $XYMONSERVERROOT/server/etc/hosts.cfg | awk '{print $2;}')


# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
# Xymon checks
#

# Query Xymon for last checks of each service:
QUERYBOARD="xymondboard fields=hostname,testname,color,line1"
XYMONBOARD=$($XYMONSERVERROOT/server/bin/xymon $XYMSRV "$QUERYBOARD" | sed "s/<\!\-\-.*\-\->//g")
if [[ ! $? ]]; then
  echo "ERROR: Couldn't check for xymondboard." >&2
  exit $STATE_UNKNOWN
fi
# Values in board are separated by '|' (pipes).
# Each line has: hostname|testname|color|line1.
echo "$XYMONBOARD" | while read check; do
  # Parse host:
  host=$(echo $check | awk -F'|' '{print $1;}')
  # Parse service (test):
  testname=$(echo $check | awk -F'|' '{print $2;}')
  # Parse state color:
  color=$(echo $check | awk -F'|' '{print $3;}')
  # Parse service output status:
  line1=$(echo $check | awk -F'|' '{print $4;}')
  if [[ -z $line1 ]]; then
    # This means that Xymon is not reporting any text in service status. So I put the color:
    line1="$color"
  fi
  # We need the state to be a number: 0 (ok), 1 (warn), 2 (crit) or 3 (unkn):
  state=$(color_to_code $color)
  # Check if there are rrd files for this service:
  rrdfiles=$(rrd_by_service $host $testname $XYMONRRDS)
  perf=""
  if [[ $rrdfiles ]]; then
    # This means there is at least one metric for this service.
    # Now, if we have more than one metric, we should create one service per metric.
    rrdcount=$(echo "${rrdfiles}"|awk -F'##' '{print NF-1;}')
    # Replace '##' with spaces, so for can loop over the files:
    rrdfiles=$(echo "${rrdfiles}"|sed "s/##/ /g")
    if [[ $rrdcount -gt 1 ]]; then
      # This means there is more than one metric.
      for file in $rrdfiles; do
      #if [[ $perf ]]; then
        #perf="${perf}|"
      #fi
        metric=$(echo "${file}"|sed "s/=.*$//g")
        rrdfile=$(echo "${file}"|sed "s/^.*=//g")
        value=$(rrd_last_value $rrdfile)
        perf="${metric}=${value}"
        echo "$state ${host}_${testname}_${metric} $perf $line1"
      done
    else
      # This means there is only one metric.
      metric=$(echo "${rrdfiles}"|sed "s/=.*$//g")
      rrdfile=$(echo "${rrdfiles}"|sed "s/^.*=//g")
      value=$(rrd_last_value $rrdfile)
      perf="${metric}=${value}"
      echo "$state ${host}_${testname} $perf $line1"
    fi
  else
    # In this case we only have one service and no perf data.
    # So I set perf data to availability:
    perf="available=$(color_to_avail $color)"
    echo "$state ${host}_${testname} $perf $line1"
  fi
done

