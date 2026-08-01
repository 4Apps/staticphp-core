#!/bin/bash
#
# Install with:  ln -sf ../../scripts/git_pre_commit.bash .git/hooks/pre-commit

# Find base path
BASE_PATH=$(dirname $(readlink -f "$0"))/..
BASE_PATH="`cd $BASE_PATH;pwd`"

# Git stuff
COMMIT="HEAD"


# Test non-ascii filenames
echo "*Testing non-ascii filenames.. "
if [ $(git diff --cached --name-only --diff-filter=A -z $COMMIT | LC_ALL=C tr -d '[ -~]\0' | wc -c) -gt 0 ]; then
    echo "Error: Attempt to add a non-ascii file name."
    echo
    echo "This can cause problems if you want to work"
    echo "with people on other platforms."
    echo
    echo "To be portable it is advisable to rename the file ..."
    echo
    exit 1
fi
echo " Done"
echo


# Run the same checks CI runs, so a green commit means a green pipeline.
# There is no docker compose service to fall back on when the working copy has no vendor -
# this is a library, and composer install is the only setup it needs.
echo "*Running code tests.. "
if [ -f /.dockerenv ]; then
    ./scripts/code_tests.bash
else
    docker compose run --rm --no-deps develop /srv/app/scripts/code_tests.bash
fi
if [ "$?" != "0" ]; then
    echo "!!! ERROR: code tests failed !!!"
    exit 1
fi
echo " Done"
echo


# Test for whitespace errors
echo "*Testing for whitespace errors.. "
git diff-index --cached --check $COMMIT --
if [ "$?" != "0" ]; then
    echo "!!! ERROR !!!"
    exit 1
fi
echo " Done"
echo
