#!/bin/bash
# Exit on any error
set -e

# This script must be run as a superuser
if test `whoami` != "root" ; then
    echo "This script must be run as a superuser; launch it with:"
    echo "    sudo $0"
    exit 1
fi

# Ensure we're using UK local time on the Pi
cp /usr/share/zoneinfo/Europe/London /etc/localtime

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
sudo -u pi echo $SHORTCODE > /home/pi/becshortcode

# Generate the BEC Slideshow URL using SHORTCODE
BECURL=http://localhost/services/slideshow.php?$SHORTCODE
echo BEC URL will be $BECURL

# Ensure iceweasel, xdotool and unclutter are installed
echo Installing packages...
apt-get -y update
apt-get -y install iceweasel xdotool unclutter

# Ensure any logged-in user can use ping
setcap 'cap_net_raw=+ep' $(which ping)

# Disable bluetooth by default
if [ ! -f /etc/default/bluetooth.old ]; then
    mv /etc/default/bluetooth /etc/default/bluetooth.old
fi
cat /etc/default/bluetooth.old | sed -e "s/^BLUETOOTH_ENABLED=1$/BLUETOOTH_ENABLED=0/" > /etc/default/bluetooth

#
# Ensure Firefox/iceweasel transfers minimal unrequested data
# Many tips from: http://www.ghacks.net/2015/08/18/a-comprehensive-list-of-firefox-privacy-and-security-settings/
#
if [ ! -f /etc/firefox-esr/firefox-esr.js.old ]; then
    mv /etc/firefox-esr/firefox-esr.js /etc/firefox-esr/firefox-esr.js.old
fi
cat /etc/firefox-esr/firefox-esr.js.old | sed -e 's@"extensions.update.enabled",\s*true@"extensions.update.enabled", false@' > /etc/firefox-esr/firefox-esr.js
echo 'lockPref("extensions.update.autoUpdateDefault", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("extensions.getAddons.cache.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("lightweightThemes.update.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("dom.ipc.plugins.flash.subprocess.crashreporter.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("dom.ipc.plugins.reportCrashURL", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("toolkit.telemetry.unified", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("toolkit.telemetry.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("toolkit.telemetry.unifiedIsOptIn", true);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("browser.selfsupport.enabled", false);' >> /etc/firefox-esr/firefox-esr.js

echo 'lockPref("experiments.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("experiments.supported", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("experiments.activeExperiment", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("network.allow-experiments", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("browser.tabs.crashReporting.sendReport", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("browser.newtab.preload", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("browser.newtabpage.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("browser.newtabpage.enhanced", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("browser.newtabpage.introShown", true);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("extensions.pocket.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("social.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("reader.parse-on-load.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("dom.flyweb.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("app.update.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("app.update.auto", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("app.update.mode", 0);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("app.update.slient", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("app.update.staging.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("app.update.service.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("browser.search.update", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("browser.search.suggest.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("browser.safebrowsing.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("browser.safebrowsing.malware.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("browser.safebrowsing.phishing.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("browser.safebrowsing.downloads.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("browser.safebrowsing.downloads.remote.enabled", false);' >> /etc/firefox-esr/firefox-esr.js

echo 'lockPref("browser.safebrowsing.provider.google.updateURL", "");' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("browser.safebrowsing.provider.google.gethashURL", "");' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("browser.safebrowsing.provider.google4.updateURL", "");' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("browser.safebrowsing.provider.google4.gethashURL", "");' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("browser.safebrowsing.provider.mozilla.gethashURL", "");' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("browser.safebrowsing.provider.mozilla.updateURL", "");' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("captivedetect.canonicalURL", "");' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("network.captive-portal-service.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("browser.send_pings", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("browser.send_pings.require_same_host", true);' >> /etc/firefox-esr/firefox-esr.js

echo 'lockPref("extension.blocklist.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("services.blocklist.update_enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("network.dns.disablePrefetch", true);' >> /etc/firefox-esr/firefox-esr.js
#echo 'lockPref("security.OCSP.enabled", 0);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("geo.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("network.predictor.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("network.predictor.enable-prefetch", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("services.sync.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("browser.microsummary.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("browser.microsummary.updateGenerators", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'clearPref("extensions.lastAppVersion");' >> /etc/firefox-esr/firefox-esr.js
echo 'pref("browser.rights.3.shown", true);' >> /etc/firefox-esr/firefox-esr.js
echo 'pref("browser.startup.homepage_override.mstone","ignore");' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("plugins.hide_infobar_for_outdated_plugin", true);' >> /etc/firefox-esr/firefox-esr.js
echo 'clearPref("plugins.update.url");' >> /etc/firefox-esr/firefox-esr.js
echo 'pref("datareporting.healthreport.service.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'pref("datareporting.healthreport.uploadEnabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("datareporting.policy.dataSubmissionEnabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'lockPref("toolkit.crashreporter.enabled", false);' >> /etc/firefox-esr/firefox-esr.js
echo 'Components.classes["@mozilla.org/toolkit/crash-reporter;1"].getService(Components.interfaces.nsICrashReporter).submitReports = false;' >> /etc/firefox-esr/firefox-esr.js 

#
# TODO: Disable use of ntpd - we'll set the time from the local router
# Note: May not be able to do this everywhere
#apt-get -y remove ntp

#
# Install local LAMP server software (some of these are interactive)
#
apt-get -y install apache2
apt-get -y install mysql-server mysql-client
mysql_secure_installation
apt-get -y install php5 php5-fpm php5-mysql php5-curl libapache2-mod-php5
# Allow URL rewrites
a2enmod rewrite
cp -f /etc/apache2/sites-available/000-default.conf /etc/apache2/sites-available/000-default.conf.old 
echo "<Directory /var/www/html>" >> /etc/apache2/sites-available/000-default.conf
echo -e "\tOptions Indexes FollowSymLinks MultiViews" >> /etc/apache2/sites-available/000-default.conf
echo -e "\tAllowOverride All" >> /etc/apache2/sites-available/000-default.conf
echo -e "\tOrder allow,deny" >> /etc/apache2/sites-available/000-default.conf
echo -e "\tallow from all" >> /etc/apache2/sites-available/000-default.conf
echo "</Directory>" >> /etc/apache2/sites-available/000-default.conf

#
# Create the bec database and give www-data permissions
#
echo "CREATE USER 'www-data'@'localhost'; CREATE DATABASE bec; GRANT CREATE, ALTER, INSERT, SELECT, UPDATE on bec.* to 'www-data'@'localhost'; FLUSH PRIVILEGES;" | mysql -u root -p || echo Ignoring failure as step probably already carried out

# TODO: Generation data should come from a local database which is updated
# TODO: when turned on and then at every 9am, but only with data that
# TODO: it doesn't already have.





# First run of the becdisplayonly.php script to seed the bec database with site and meter data
if [ ! -e /var/www/bec_services/simtricity_token.txt ] ; then
    sudo -u www-data ln -s /var/www/html/simtricity_token.txt /var/www/bec_services/
fi
sudo -u www-data php /var/www/bec_services/becdisplayonly.php -u -v

# Get the SQL to load in the extra tables needed for extra site info and the slide-show
sudo -u pi curl http://livegen.bristolenergy.coop/services/low_data_pi.sql > low_data_pi.sql
mysql -u root -p bec < low_data_pi.sql

# Try to create directory /home/pi/bin in case it doesn't exist already
sudo -u pi mkdir -p /home/pi/bin

# Try to create directory /home/pi/.config/autostart in case it doesn't exist already
sudo -u pi mkdir -p /home/pi/.config/autostart

##############################################################################
# Put the following file in /home/pi/bin/bec_slideshow.sh
##############################################################################
sudo -u pi cat > /home/pi/bin/bec_slideshow.sh <<-EOF
#!/bin/bash

#
# TODO: Grab the date and time from the local router to update the Pi's clock
#
curl http://192.168.1.1 > dateandtime.txt

# Stop the avahi-daemon (bonjour service)
sudo service avahi-daemon stop

# Prevent DPMS and screen blanking
xset -display ":0" s off
xset -display ":0" -dpms
xset -display ":0" s noblank

# Remove the old mozilla profile so we always start the browser from 'fresh'
rm -rf ~/.mozilla

#
# TODO: Attempt to update the generation data in the database
#



export COUNT=0
export TEXT="Bristol Energy Cooperative: Waiting for local slide-show server to start..."
while ! wget --spider http://localhost 2>&1 | grep connected ; do
    bash -c "echo 1 ; sleep 8 ; echo 100" | zenity --progress --text="\$TEXT" --pulsate --auto-close --auto-kill
    export COUNT=\$(( $COUNT + 1 ))
    if [ \$COUNT -eq 7 ]; then
        export TEXT="Waiting for local slide-show server to start...but it has been a while - perhaps try rebooting me"
    fi
done


# Launch Iceweasel - Firefox for Debian 
iceweasel $BECURL &

# Wait for Iceweasel (calls could be iceweasel or firefox) to get going
export STARTED=0
while test "\$STARTED" -ne 1 ; do
    sleep 1
    if [ 1 -eq \$(xdotool search --onlyvisible --class iceweasel | wc -l) ] ; then
        export STARTED=1
    fi
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

# Wait until the time has synchronised from an NTP server, then stop the ntp service
# TODO: Remove if we are picking up date and time from router instead
ntp-wait -v
sudo service ntp stop

EOF
##############################################################################
chmod a+x /home/pi/bin/bec_slideshow.sh


##############################################################################
# Put the following file in /home/pi/.config/autostart/BEC Slideshow.desktop (a 'shortcut' icon)
##############################################################################
sudo -u pi cat > "/home/pi/.config/autostart/BEC Slideshow.desktop" <<-EOF
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
##############################################################################

# Create a link on the desktop too (removing any pre-existing one)
rm -f "/home/pi/Desktop/BEC Slideshow.desktop"
sudo -u pi ln -s "/home/pi/.config/autostart/BEC Slideshow.desktop" "/home/pi/Desktop/BEC Slideshow.desktop"

# All done - reboot!
echo Rebooting in a few seconds...
sleep 8
shutdown -r now
