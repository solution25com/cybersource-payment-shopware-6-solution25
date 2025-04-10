#!/bin/bash

# Run PHP linting on all PHP files
find -L . -path ./vendor -prune -o -path ./tests -prune -o -name '*.php' -print0 | xargs -0 -n 1 -P 4 php -l
