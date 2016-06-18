#!/bin/bash
# Exit on any error
set -e

# This script must be run as a superuser
if test `whoami` != "root" ; then
    echo "This script must be run as a superuser; launch it with:"
    echo "    sudo $0"
    exit 1
fi

echo "What is the BEC Slideshow short code (short name used in URLs) for the building/site this Raspberry Pi is to be installed in?"
echo "For example, Hamilton House is hh and South Bristol Sports Centre is sbsc."
SHORTCODE=""
while [[ $SHORTCODE = "" ]]; do 
    read -p "Short code: " SHORTCODE
    if [ `echo $SHORTCODE | wc -m` -lt 2 ] || [ `echo $SHORTCODE | wc -m` -gt 5 ] || [[ ! $SHORTCODE =~ ^[A-Za-z0-9]+$ ]] ; then
        echo "Short codes are between 1 and 4 characters long and only contain alphanumeric characters.  '$SHORTCODE' is not a valid short code - please try again."
        SHORTCODE=""
    fi
done

# Put in a crontab to turn off the display at midnight and wake it up at 8am
cat > /home/pi/crontab.txt <<-EOF
# Format: <minute> <hour> <day-of-month> <month> <day-of-week> <command>
# Ranges and comma-separated lists are allowed

# Force screen off at night
00 00 * * * xset -display ":0" dpms force off

# Force screen on and disable automatic DPMS in the morning
00 08 * * * xset -display ":0" dpms force on; xset -display ":0" -dpms
EOF
crontab -u pi /home/pi/crontab.txt

# Generate the BEC Slideshow URL using SHORTCODE
BECURL=http://bec-monitoring.spiraledge.co.uk/slideshow.php?$SHORTCODE
echo BEC URL will be $BECURL 

# Ensure iceweasel, xdotool, unclutter and x11vnc are installed
echo Installing packages...
apt-get -y install iceweasel xdotool unclutter x11vnc

# Try to create directory /home/pi/bin in case it doesn't exist already
mkdir /home/pi/bin || true > /dev/null 2>&1

# Put the following file in /home/pi/bin/bec_slideshow.sh
cat > /home/pi/bin/bec_slideshow.sh <<-EOF
#!/bin/bash

# Prevent DPMS and screen blanking
xset -display ":0" s off
xset -display ":0" -dpms
xset -display ":0" s noblank

# Remove the old mozilla profile so we always start the browser from 'fresh'
rm -rf ~/.mozilla

# Launch Iceweasel - Firefox for Debian 
iceweasel $BECURL &

# Wait a while to let Iceweasel get going
sleep 15

# Press F11 to enter fullscreen mode
xdotool key --clearmodifiers F11

# Remove the mouse pointer
unclutter &
EOF
chmod a+x /home/pi/bin/bec_slideshow.sh

# Put the following file in /home/pi/.config/autostart/BEC Slideshow.desktop (a 'shortcut' icon)
cat > "/home/pi/.config/autostart/BEC Slideshow.desktop" <<-EOF
[Desktop Entry]
Encoding=UTF-8
Name=BEC Slideshow
Comment=Show Bristol Energy Cooperative PV generation data
GenericName=BEC Slideshow
X-GNOME-FullName=BEC Slideshow
Exec=/home/pi/bin/bec_slideshow.sh
Terminal=false
X-MultipleArgs=false
Type=Application
Icon=iceweasel
Categories=Network;WebBrowser;
MimeType=text/html;text/xml;application/xhtml+xml;application/xml;application/vnd.mozilla.xul+xml;application/rss+xml;application/rdf+xml;image/gif;image/jpeg;image/png;x-scheme-handler/http;x-scheme-handler/https;
StartupWMClass=Iceweasel
StartupNotify=true
EOF

# Create a link on the desktop too
ln -s "/home/pi/.config/autostart/BEC Slideshow.desktop" "/home/pi/Desktop/BEC Slideshow.desktop"

# Reboot
shutdown -r now
