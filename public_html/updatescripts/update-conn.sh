#!/bin/bash
# Script to update a Raspberry Pi installation for the site using the short-code
# which is part of the name of this script.

################################################################################
# The line below is parsed to find out which version this script will update the
# installation to.  The version number should be an integer and no other line is
# allowed to contain the BEC-underscore-VERSION tag or the update may fail.
# BEC_VERSION 3
################################################################################

# Exit the script early if there are any errors
set -e

################################################################################
# Put code to perform update actions below
################################################################################

# The simplest way to update a Pi would be to replace and run the
# raspberry_install.sh script with the latest version of it.
cd /home/pi/bin
rm -f raspberry_install.sh
wget http://livegen.bristolenergy.coop/services/raspberry_install.sh
chmod a+x raspberry_install.sh

################################################################################
# Done!  If we got here, we should have successfully updated: update the number
# in the becversion file.  The number below should be the same as above.
################################################################################
echo 3 > /home/pi/becversion

################################################################################
# Final action
################################################################################
# We need the final action to be running the raspberry_install.sh script which
# actually completes the update process in this case.  We're already running
# this script with sudo permissions, so can just run it having ensured the site
# SHORTCODE is in the environment.
SHORTCODE=`cat /home/pi/becshortcode` /home/pi/bin/raspberry_install.sh