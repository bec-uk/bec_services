<?php

/**
 * Miscellaneous helper functions
 */


/**
 * Expands the home directory alias '~' to the full path.
 * @param string $path the path to expand.
 * @return string the expanded path.
 */
function expandHomeDirectory($path)
{
    $homeDirectory = getenv('HOME');
    if (empty($homeDirectory))
    {
        $homeDirectory = getenv("HOMEDRIVE") . getenv("HOMEPATH");
    }
    return str_replace('~', realpath($homeDirectory), $path);
}


/**
 * Helper function to take off the BST offset if necessary to make all times GMT/UTC.
 * Only call this function on DateTimes created from sources which use local time - if
 * they are already fixed at UTC year-round, this won't know that!
 *
 * @param DateTime $dateTime
 */
function dateTimeToGMT(&$dateTime)
{
    // If this date & time is in BST, take 1 hour off to put it back into GMT/UTC
    // so we have a common base for comparison with other data
    $tz = new dateTimeZone('Europe/London');
    $trans = $tz->getTransitions($dateTime->getTimestamp(), $dateTime->getTimestamp());
    if ($trans[0]['offset'] > 0) {
        if (DEBUG) {
            print('In BST - altering ' . $dateTime->format('Y-m-d H:i:s'));
        }
        $dateTime->sub(new DateInterval('PT1H'));
        if (DEBUG) {
            print(' to ' . $dateTime->format('Y-m-d H:i:s') . "\n");
        }
    }
}
