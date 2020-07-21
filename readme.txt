Bristol Energy Cooperative Fault Monitoring System
==================================================

The becfm.php script is the main BEC Fault Monitoring System script.  It is
intended to be run daily to:
 - retrieve the latest power and energy data for our PV solar installations
 - retrieve the latest weather-related data for use in assessing PV
   performance
 - assess performance of the solar PV installations
 - send alerts if any installation appears to be under-performing


Installation
------------

Requirements:
 - PHP 5.x or 7.x with curl and PDO enabled
   	Install the php-mysql package or you could try: sudo pecl install pdo_mysql
 - The Composer dependency management tool (https://getcomposer.org/)
 - The Google Client Library and the Overcast API which will both be downloaded
   using Composer with the command:
       composer install
 - MySQL database server (by default; PDO may allow use with other databases,
   but SQL statements used may need modification in the scripts)
 - PHPGraphLib if graphing functionality is desired - can be retrieved using
   git with the command:
       git clone https://github.com/elliottb/phpgraphlib.git
 
To use the scripts, a database schema of the name found in the becfm.ini file
should be configured and usable by the user specified in the becfm.ini file.

Create Centre meteorological data is expected to be sent in CSV files emailed
daily to a Gmail account.  For the scripts to retrieve the CSV files and
import the data, access credentials are required.  To set these up, follow the
instructions under "Turn on the Gmail API" here:
	https://developers.google.com/gmail/api/quickstart/php

The first time becfm.php is run, it will need you to login to the Google
account and save an access token.

The Simtricty platform is where the BEC meter and power data is retrieved from.
This too requires an access token.  To get an access token:
 - login to the Simtricity web interface - https://trial.simtricity.com/
 - click on your name in the top right to access your profile
 - under "Access Tokens" in the bottom left, press the "Show Token" button
 - copy your token and paste it into a file called simtricity_token.txt


Launching
---------

The system can be launched from the command line by running:

  php becfm.php
  
(or if becfm.php is executable, just ./becfm.php).

Run with the -h or --help option to see the command-line options.


Configuration
-------------

The becfm.ini file contains various parameters that users may wish to configure
on installation.