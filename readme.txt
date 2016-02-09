Bristol Energy Cooperative Fault Monitoring System
==================================================

Installation
------------

Requirements:
 - PHP 5.x with curl and PDO enabled
 - MySQL database server (by default; PDO may allow use with others, but SQL
   statements used may need modification in the scripts)
 
To use the scripts, a database schema of the name found in the becfm.ini file
should be configured and usable by the user specified in the becfm.ini file.

Create Centre meterological data is expected to be sent in CSV files emailed
daily to a Gmail account.  For the scripts to retrieve the CSV files and
import the data, access credentials are required.  To set these up ...

FIXME: Finish writing me!


The Simtricty platform is where the BEC meter and power data is retrieved from.
This too requires an access token.  To get an access token, login to the
Simtricity web interface and ...

FIXME: Finish writing me!


Launching
---------

The system can be launched from the command line by running:

  php becfm.php
  
(or if becfm.php is executable, just ./becfm.php).