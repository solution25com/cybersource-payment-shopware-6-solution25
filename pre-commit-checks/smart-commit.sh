#!/bin/bash

INPUT_FILE=$1

# Check if the commit message file exists
if [ -e "$INPUT_FILE" ]; then
    COMMIT_MSG=`head -n1 $INPUT_FILE`
    # COMMIT_MSG="CSC-123 #time 1h asd"
    PATTERN="^(CSC)-[[:digit:]]+ #time [[:digit:]]+(h|m) "

    # Check if the commit message matches the conventional commit format
    if ! [[ "$COMMIT_MSG" =~ $PATTERN ]]; then
        echo "Error: Invalid commit message format. Please use the conventional commit format."
        echo "Example of smart commit message: CSC-123 #time 1h commit message"
        exit 1
    fi
fi
exit 0
