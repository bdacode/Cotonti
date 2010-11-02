<?php
/* ====================
[BEGIN_COT_EXT]
Hooks=headrc
[END_COT_EXT]
==================== */

/**
 * Head resources
 *
 * @package tags
 * @version 0.7.0
 * @author Trustmaster
 * @copyright Copyright (c) Cotonti Team 2008-2010
 * @license BSD
 */

defined('COT_CODE') or die('Wrong URL');


//cot_headrc_load_file($cfg['plugins_dir'] . '/tags/style.css', 'global', 'css');
if ($cfg['jquery'] && $cfg['turnajax'] && $cfg['plugin']['tags']['autocomplete'] > 0)
{
	cot_headrc_load_file('js/jquery.autocomplete.js');
	cot_headrc_load_embed('tags.autocomplete', '$(document).ready(function(){
$(".autotags").autocomplete("'.cot_url('plug', 'r=tags').'", {multiple: true, minChars: '.$cfg['plugin']['tags']['autocomplete'].'});
});');
}
?>