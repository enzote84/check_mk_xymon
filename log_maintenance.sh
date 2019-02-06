#!/bin/bash
#
# Log file rotation
#
# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
#
# Version: 1.0
# Author: DEMR
#
# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -

# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
# Info
#
AUTHOR="DEMR"
VERSION="1.0"
PROGNAME=$(basename $0)
DIRNAME=$(dirname $0)

# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
# Parameters
#
DATE=$(date +%Y%m%d%H%M)

# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
# Identify log files
#
LOGS=$(ls $DIRNAME/*.log)

# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
# Move log files
#
for log in $LOGS; do
  cp -p ${log} ${log}.${DATE}
  cat /dev/null > ${log}
done

# - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - -
# Delete old files
#
find $DIRNAME/*log* -mtime 30 -exec rm -f '{}' \;

