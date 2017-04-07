<HTML>

<HEAD>
<TITLE>Bristol Energy Cooperative - Slideshow</TITLE>
<link rel="stylesheet" type="text/css" href="slideshow.css">
</HEAD>

<?php

/**
 * PHP to get slideshow URLs and display periods for a site.
 * The site short-code must be specified as a parameter.
 **/

include "utils.php";

/* Function which looks for special tokens in a string and performs string replacement.
 * The substitution table can be found below ($substTable).
 */
function substituteInURL($url, $substTable)
{
    foreach ($substTable as $search => $replace)
    {
        $url = str_replace($search, $replace, $url);
    }
    return $url;
}


/******************************************************************************/

// Location to pick up ini file from to override default database access parameters
chdir('..');
define('INI_FILENAME', 'slideshow_db.ini');
$iniFilename = INI_FILENAME;

// Only one key expected (the site short-code)
if (count($_GET) != 1)
{
    $errorMessage = "Error: Invalid query - a single site short-code was expected";
    goto errorMessage;
}

// Retrieve the site short-code from the URL that was used to access this page
$sitecode = key($_GET);

// Default database access parameters which can be overriden by the ini file
$ini = array('database_type' => 'mysql',
             'database_host' => 'localhost',
             'database_name' => 'bec',
             'database_username' => 'www-data',
             'database_user_password' => '');

// Read configuration from ini file to override defaults
if (file_exists($iniFilename))
{
    $ini = array_merge($ini, parse_ini_file($iniFilename));
}

$dbHandle = FALSE;
try
{
    $dbHandle = new PDO($ini['database_type'] . ":host=" . $ini['database_host'] . ";dbname=" . $ini['database_name'],
                        $ini['database_username'], $ini['database_user_password']);
}
catch (Exception $e)
{
    print('Exception: ' . $e->getMessage());
    print("<br>");
}

if ($dbHandle === FALSE)
{
    $errorMessage = "Error: Failed to connect to " . $ini['database_type'] . " '" . $ini['database_name'] . "' database";
    goto errorMessage;
}

// First check for a valid site short-code
$sql = "SELECT COUNT(1) AS size FROM slideshow_sites WHERE sitecode='$sitecode'";
$result = runQuery($dbHandle, $sql);
if (gettype($result) != 'array')
{
    $errorMessage = $result;
    goto errorMessage;
}
if ($result[0]['size'] == 0)
{
    $errorMessage = "Error: Did not find '$sitecode' in slideshow_sites table\n";
    goto errorMessage;
}

// Next see if data limits apply at this site (should it include just the 'all' pseudo-site, or
// both the 'all' pseudo-site and the 'all_no_lim' pseudo-site too?
$sql = "SELECT data_limits FROM slideshow_sites WHERE sitecode='$sitecode'";
$result = runQuery($dbHandle, $sql);
if (gettype($result) != 'array')
{
    $errorMessage = $result;
    goto errorMessage;
}
$dataLimits = $result[0]['data_limits'];

// Form our SQL query
$sql = "SELECT slideshow_urls.url, slideshow_urls.display_period_secs, slideshow_urls.is_image
        FROM slideshow_site_to_url JOIN slideshow_urls ON slideshow_site_to_url.url_id = slideshow_urls.id
        WHERE sitecode=\"$sitecode\" OR sitecode=\"all\"";
if ($dataLimits == 0)
{
    $sql .= ' OR sitecode="all_no_lim"';
}
$sql .= ' ORDER BY slideshow_site_to_url.ordering';
$result = runQuery($dbHandle, $sql);
if (gettype($result) != 'array')
{
    $errorMessage = $result;
    goto errorMessage;
}

# Subtitutions table
$substTable = array("%SITECODE%" => $sitecode);
if ($dataLimits == 1)
{
    // Assume this display is serving it's own pages locally - substitute
    // livegen.bristolenergy.coop with localhost.
    $substTable['livegen.bristolenergy.coop'] = 'localhost';
}

goto success;

// If something went wrong we end up here
errorMessage:
?>
<BODY>
Failed to retrieve slideshow URLs to display.<br>
<?php
print($errorMessage);
?>
</BODY>
</HTML>

<?php
exit;

// All went well - display our slideshow!
success:
?>
<BODY>

<div style="height:100%; background:url(bec_logo_and_name.png); background-repeat:no-repeat; background-position:center">
<!--
    <div style="vertical-align:middle; height:110px">
        <p style="vertical-align:middle; color:#555555; font-size:50px; font-family:Arial; margin-top:0px; margin-bottom:0px;">
            <img id="becLogo" src="bec_logo_100.png" alt="[LOGO]" style="vertical-align:middle">
            <b>Bristol Energy</b> Cooperative
        </p>
    </div>
-->
    <div class="slideshow-container">
<?php
// Put <div>s for each of the slides we'll show in place.  The slides will all be loaded as this page is loaded.
$count = 0;
foreach ($result as $slide)
{
    print('        <div class="mySlides ' . ($count == 0 ? 'visible' : 'hidden') . '" style="z-index: ' . ($count + 1) . '">' . "\n");
    /* We also store the display period (converting seconds to ms and adding 3
       seconds for animation time) in a private data attribute called timeout
       for later use by some Javascript.
     */
    $thisURL = $slide['url'];
    # Perform substiutions on the URL if needed
    $thisURL = substituteInURL($thisURL, $substTable);

    # During the initial page load we only load the first slide to make the page load
    # more quickly.  Javascript then loads the rest of them in the background.
    if ($count == 0)
    {
        $urlOnLoad = $thisURL;
    }
    else
    {
        $urlOnLoad = 'about:blank';
    }

    // Multiply by 1000 to get ms, then add 3000 for the transistion (fade in/out) time
    $thisDisplayPeriod = $slide['display_period_secs'] * 1000 + 3000;
    if ($slide['is_image'] == 1)
    {
        // It's an image - scale it to fit
        print('            <img style="height: 100%; width: 100%; object-fit: contain" src="' . $urlOnLoad . '" data-url="' . $thisURL . '" data-timeout=' . $thisDisplayPeriod . '>' . "\n");
    }
    else
    {
        // Not an image - load in an IFRAME
        print('            <IFRAME id="slide' . $count . '" scrolling="no" src="' . $urlOnLoad . '" data-url="' . $thisURL . '" data-timeout=' . $thisDisplayPeriod . '></IFRAME>' . "\n");
    }
    print('        </div>' . "\n");
    $count++;
}

# Record this page fetch to the IP address log file
writeIPLog($sitecode);
?>
    </div>
</div>
<script>

// The index of the slide we're showing
var slideIndex = 0;

// The index of the slide that next needs loading in the background
var loadIndex = 1;

// Array of slides
var slides = document.getElementsByClassName("mySlides");

// Once the page has finished loading:
//  - Call the background loader to load the rest of the slides
//  - Call our cycling slideshow functions to cycle through the slides
window.onload = function()
{
    // Pause for 1 second and load the next slide in the background
    setTimeout(backgroundLoader, 1000);
    // Start the slide-show
    setTimeout(hideSlide, slides[slideIndex].childNodes[1].attributes['data-timeout'].value);

<?php
	if ($dataLimits <> 1) goto skipLowDataLogging;
?>
    // Low data logging
    testFetch = new XMLHttpRequest();
    testFetch.onload = function()
    {
        return (this.status);
    };
    var quickToFetchURL = <?php print("\"http://livegen.bristolenergy.coop/services/lowdata.php?" . $sitecode . "\";");?>
    testFetch.open("HEAD", quickToFetchURL);
    testFetch.send();
<?php
    skipLowDataLogging:
?>
};

// Function to background load the slides which were left blank on initial load
function backgroundLoader()
{
    if (loadIndex < slides.length)
    {
        // Queue up the loading of the next slide a second after this one is loaded
        slides[loadIndex].childNodes[1].onload = setTimeout(backgroundLoader, 1000);

        // Trigger the loading of this slide
        slides[loadIndex].childNodes[1].src = slides[loadIndex].childNodes[1].attributes['data-url'].value;

        // Increment to next slide
        loadIndex++;
    }
}

// Function to hide a slide and show the next after a delay for the hide animation
function hideSlide()
{
    // Hide the current slide
    slides[slideIndex].className = "mySlides hidden";
    // Pause to let the animation run before calling showSlide()
    setTimeout(showSlide, 1500);
}

// Function to increment the current slide number and show that slide, lining up the
// hide function to by run after it has been displayed as long as requested
function showSlide()
{
    slideIndex++;
    if (slideIndex >= slides.length)
    {
        slideIndex = 0;
    }
    // Show the new current slide
    slides[slideIndex].className = "mySlides visible";
    // Call again after the display period has elapsed
    setTimeout(hideSlide, slides[slideIndex].childNodes[1].attributes['data-timeout'].value);
}

// Function to refresh the current page if the server is currently contactable
// If the page cannot be fetched, we don't reload, just keeping the present
// page displayed.
function fullReload()
{
    // Before we simply reload the page we want make sure it is likely to load as if it
    // didn't we'd be left with a blank screen which would not refresh again.
    testFetch = new XMLHttpRequest();
    testFetch.onload = function()
    {
        if (this.status == 200 && this.statusText == 'OK')
        {
            // Success - perform the reload
            window.location.reload(true);
        }
        return (this.status);
    };
    var quickToFetchURL = window.location.href.slice(0, window.location.href.indexOf('?'));
    testFetch.open("HEAD", quickToFetchURL);
    testFetch.send();
}

// Regular full refresh of the page so that any changes on the server are reflected
window.setInterval("fullReload();", <?php if ($dataLimits == 1) { print("12 * "); }?>60 * 60 * 1000);

</script>

</BODY>
</HTML>
