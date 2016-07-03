#!/bin/bash
# Script to update a Raspberry Pi installation for the site using the short-code
# which is part of the name of this script.

################
# The line below is parsed to find out which version this script will update the
# installation to.  The version number should be an integer and no other line is
# allowed to contain the BEC-underscore-VERSION tag or the update may fail.
# BEC_VERSION 1
################

# Exit the script early if there are any errors
set -e

##########################################
# Put code to perform update actions below
##########################################






##########################################
# Done!  If we got here, we should have successfully updated: update the number
# in the becversion file.  The number below should be the same as above.
##########################################
echo 1 > /home/pi/becversion

