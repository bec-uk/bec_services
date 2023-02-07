#!/bin/bash
# Exit on any error
set -e

# This script must be run as a superuser
if test `whoami` != "root" ; then
    echo "This script must be run as a superuser; launch it with:"
    echo "    sudo $0"
    exit 1
elif [ `echo $SUDO_USER | wc -w` -ne 1 ] ; then
    echo Could not identify normal login user using the environment variable SUDO_USER - stopping
    exit 1
fi

# Ensure we're using UK local time on the Pi
if diff /usr/share/zoneinfo/Europe/London /etc/localtime; then
    echo Already using UK timezone
else
    echo Updating to use UK timezone
    cp /usr/share/zoneinfo/Europe/London /etc/localtime
fi

# We use a pre-existing SHORTCODE from the environment if there is one in case
# this is running as an update rather than a fresh install.
if [ `echo $SHORTCODE | wc -m` -lt 2 ] || [ `echo $SHORTCODE | wc -m` -gt 5 ] || [[ ! $SHORTCODE =~ ^[A-Za-z0-9]+$ ]] ; then
    # SHORTCODE from environment was not valid - prompt for one
    SHORTCODE=""
    echo "What is the BEC Slideshow short code (short name used in URLs) for the building/site this Raspberry Pi is to be installed in?"
    echo "For example, Hamilton House is hh and South Bristol Sports Centre is sbsc."

    while [[ $SHORTCODE = "" ]]; do 
        read -p "Please enter site short code: " SHORTCODE
        if [ `echo $SHORTCODE | wc -m` -lt 2 ] || [ `echo $SHORTCODE | wc -m` -gt 5 ] || [[ ! $SHORTCODE =~ ^[A-Za-z0-9]+$ ]] ; then
            echo "Short codes are between 1 and 4 characters long and only contain alphanumeric characters.  '$SHORTCODE' is not a valid short code - please try again."
            SHORTCODE=""
        fi
    done
fi

# Write a file containing the shortcode
sudo -u $SUDO_USER echo $SHORTCODE > /home/$SUDO_USER/becshortcode

# Generate the BEC Slideshow URL using SHORTCODE
BECURL=http://livegen.bristolenergy.coop/services/slideshow.php?$SHORTCODE
echo BEC URL will be $BECURL

# Ensure firefox-esr, xdotool and unclutter are installed
echo Installing packages...
apt-get -y update
apt-get -y install firefox-esr xdotool unclutter

# Ensure any logged-in user can use ping
setcap 'cap_net_raw=+ep' $(which ping)

# Disable bluetooth by default
mv /etc/default/bluetooth /etc/default/bluetooth.old
cat /etc/default/bluetooth.old | sed -e "s/^BLUETOOTH_ENABLED=1$/BLUETOOTH_ENABLED=0/" > /etc/default/bluetooth

# Some Raspberry Pi 3s may need WiFi power management turned off to make them stable
# We put a create a post ifup script which can do this, but leave the command commented out
echo "#!/bin/sh" > /etc/network/if-up.d/disable_wifi_power_man
echo "# Script to disable WiFi power management at boot-up which may be required for" >> /etc/network/if-up.d/disable_wifi_power_man
echo "# reliable WiFi on some Raspberry Pi 3 devices" >> /etc/network/if-up.d/disable_wifi_power_man
echo "" >> /etc/network/if-up.d/disable_wifi_power_man
echo "# Uncomment the following line to disable WiFi power management" >> /etc/network/if-up.d/disable_wifi_power_man
echo "#/sbin/iwconfig wlan0 power off" >> /etc/network/if-up.d/disable_wifi_power_man
chmod +x /etc/network/if-up.d/disable_wifi_power_man

# Try to create directory /home/$SUDO_USER/bin in case it doesn't exist already
sudo -u $SUDO_USER mkdir -p /home/$SUDO_USER/bin

# Try to create directory /home/$SUDO_USER/.config/autostart in case it doesn't exist already
sudo -u $SUDO_USER mkdir -p /home/$SUDO_USER/.config/autostart

# Disable the Firefox bookmark migration dialog by default
echo "[XRE]" > /usr/lib/firefox-esr/browser/override.ini
echo "EnableProfileMigrator=0" >> /usr/lib/firefox-esr/browser/override.ini

##############################################################################
# Put the following file in /home/$SUDO_USER/bin/bec_slideshow.sh
##############################################################################
sudo -u $SUDO_USER cat > /home/$SUDO_USER/bin/bec_slideshow.sh <<-EOF
#!/bin/bash

# Prevent DPMS and screen blanking
xset -display ":0" s off
xset -display ":0" -dpms
xset -display ":0" s noblank

# Remove the old mozilla profile so we always start the browser from 'fresh'
rm -rf ~/.mozilla

# Wait until we can contact the server before launching the browser
export COUNT=0
export TEXT="Bristol Energy Cooperative: Looking for slide-show server..."
while ! wget --spider http://livegen.bristolenergy.coop 2>&1 | grep connected ; do
    bash -c "echo 1 ; sleep 8 ; echo 100" | zenity --progress --text="\$TEXT" --pulsate --auto-close --auto-kill
    export COUNT=\$(( $COUNT + 1 ))
    if [ \$COUNT -eq 7 ]; then
        export TEXT="Looking for slide-show server...but it has been a while - check the network and if it's okay, reboot me"
    fi
done

# Launch Firefox
firefox $BECURL &

# Wait for Firefox to get going
export STARTED=0
while test "\$STARTED" -ne 1 ; do
    sleep 1
    if [ 1 -eq \$(xdotool search --onlyvisible --class firefox | wc -l) ] ; then
        export STARTED=1
    fi
done
# Give it a little longer to settle before going full-screen
sleep 5

# Press F11 to enter full-screen mode
xdotool key --clearmodifiers F11

# Move the mouse pointer out of the way
xdotool mousemove 1 20

# Remove the mouse pointer
unclutter &
EOF
##############################################################################
chmod a+x /home/$SUDO_USER/bin/bec_slideshow.sh


##############################################################################
# Put the following file in /home/$SUDO_USER/.config/autostart/BEC Slideshow.desktop (a 'shortcut' icon)
##############################################################################
sudo -u $SUDO_USER cat > "/home/$SUDO_USER/.config/autostart/BEC Slideshow.desktop" <<-EOF
[Desktop Entry]
Encoding=UTF-8
Name=BEC Slideshow
Comment=Show Bristol Energy Cooperative PV generation data
GenericName=BEC Slideshow
X-GNOME-FullName=BEC Slideshow
Exec=/home/$SUDO_USER/bin/bec_slideshow.sh
Terminal=false
X-MultipleArgs=false
Type=Application
Icon=firefox
Categories=Network;WebBrowser;
MimeType=text/html;text/xml;application/xhtml+xml;application/xml;application/vnd.mozilla.xul+xml;application/rss+xml;application/rdf+xml;image/gif;image/jpeg;image/png;x-scheme-handler/http;x-scheme-handler/https;
StartupWMClass=Firefox
StartupNotify=true
EOF
##############################################################################

# Create a link on the desktop too (removing any pre-existing one)
rm -f "/home/$SUDO_USER/Desktop/BEC Slideshow.desktop"
sudo -u $SUDO_USER ln -s "/home/$SUDO_USER/.config/autostart/BEC Slideshow.desktop" "/home/$SUDO_USER/Desktop/BEC Slideshow.desktop"


##############################################################################
# Install the update script
##############################################################################
cat > "/home/$SUDO_USER/bin/bec_autoupdate.sh" <<-EOF
#!/bin/bash
# Update script
# - pull an update script from bec.sunrise.org.uk
# - run it as root
# - reboot

# Exit on error
set -e
# Echo commands as they are run
set -x

echo
echo "***** BEC auto-update starting `date` *****"

# Extract shortcode from file
export SHORTCODE=\`cat /home/$SUDO_USER/becshortcode\`

# See which version we are currently - found in /home/$SUDO_USER/becversion
if [ -f /home/$SUDO_USER/becversion ] ; then
    export CURVER=\`cat /home/$SUDO_USER/becversion\`
else
    export CURVER=0
fi

# Download file if we can
sudo wget --unlink -T 60 -O /home/$SUDO_USER/bin/update-\$SHORTCODE.sh http://bec.sunrise.org.uk/bec/updatescripts/update-\$SHORTCODE.sh

# If the version in the file is higher than our current version, run it
export UPDATEVER=\`grep BEC_VERSION /home/$SUDO_USER/bin/update-\$SHORTCODE.sh | sed -e 's/.*BEC_VERSION *//'\`

if [ \$UPDATEVER -gt \$CURVER ]; then
    echo Detected newer version - performing update...
    sudo chmod a+x /home/$SUDO_USER/bin/update-\$SHORTCODE.sh
    sudo /home/$SUDO_USER/bin/update-\$SHORTCODE.sh
else
    echo No update found
fi
EOF
##############################################################################
chmod a+x /home/$SUDO_USER/bin/bec_autoupdate.sh


##############################################################################
# Put in a crontab to turn off the display in the evening and wake it up in
# the morning.
# We also regularly run our autoupdate.sh script.
##############################################################################
cat > /home/$SUDO_USER/crontab.txt <<-EOF
# Format: <minute> <hour> <day-of-month> <month> <day-of-week> <command>
# Ranges and comma-separated lists are allowed

# Force screen off in the evening
00 17 * * * sudo tvservice -o

# Reboot in the morning to ensure is works today even if it failed yesterday - will also bring screen on
00 09 * * * sudo reboot

# Run auto-update script hourly at 12 minutes past the hour
12 * * * * /home/$SUDO_USER/bin/bec_autoupdate.sh 2>&1 | tee -a ~/cron_autoupdate.log
# Archive old autoupdate log if it's over 32KB
10 * * * * if [ \`du -k -c ~/cron_autoupdate.log |grep total | sed -e 's/ *total//'\` -gt 32 ] ; then rm ~/cron_autoupdate.log.1 ; mv ~/cron_autoupdate.log ~/cron_autoupdate.log.1; fi

EOF
##############################################################################
crontab -u $SUDO_USER /home/$SUDO_USER/crontab.txt


# All done - reboot!
echo Rebooting in a few seconds...
sleep 8
shutdown -r now
