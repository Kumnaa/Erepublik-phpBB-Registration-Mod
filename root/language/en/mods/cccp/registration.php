<?php

/**
 *
 * hello_world [English]
 *
 * @package language
 * @version $Id: v3_modules.xml 52 2007-12-09 19:45:45Z jelly_doughnut $
 * @copyright (c) 2005 phpBB Group
 * @license http://opensource.org/licenses/gpl-license.php GNU Public License
 *
 */
/**
 * DO NOT CHANGE
 */
if (empty($lang) || !is_array($lang)) {
    $lang = array();
}

// DEVELOPERS PLEASE NOTE
//
// All language files should use UTF-8 as their encoding and the files must not contain a BOM.
//
// Placeholders can now contain order information, e.g. instead of
// 'Page %s of %s' you can (and should) write 'Page %1$s of %2$s', this allows
// translators to re-order the output of data while ensuring it remains correct
//
// You do not need this where single placeholders are used, e.g. 'Message %d' is fine
// equally where a string contains only two placeholders which are used to wrap text
// in a url you again do not need to specify an order e.g., 'Click %sHERE%s' is fine

$lang = array_merge($lang, array(
    'CCCP_EREP_REGISTRATION' => 'Username will be collected via the <a href="http://www.erepublik.com/" target="_blank">erepublik</a> API.',
    'CCCP_EREP_REG_ERROR' => 'There was a fatal error. Contact the MOD maker if this continues.',
    'CCCP_EREP_API_ERROR' => 'There was an error with the erepublik API.',
    'EREP_LOGIN_TEXT' => 'Click here to authenticate with erepublik.',
    'CCCP_USER_ADD_ERROR' => 'There was an error adding the user. Please contact the MOD maker.',
    'CCCP_USERNAME_EXISTS' => 'Username already exists.'
        ));
?>
