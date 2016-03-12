<HTML>
<HEAD>
<TITLE>Output from running becfm.php</TITLE>
</HEAD>
<BODY>
<PRE>
<!--	PHP will still run inside an HTML comment.  This comment is closed inside
	the included PHP source files.  It's a trick to allow becfm.php to be both
	a command-line script and to be included in this way without the
	#!/usr/bin/php being output!
<?php

// Launch in read-only mode with verbose output
$argv = array('becfm.php', '-v');
$argc = sizeof($argv);

chdir('..');
require_once 'becfm.php';

?> -->
</PRE>
<HR>
End of PHP output
</BODY>
</HTML>
