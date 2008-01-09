<?php
// PukiWiki Plus! - Yet another WikiWikiWeb clone.
// $Id: func.php,v 1.93.22 2008/01/05 17:32:00 upk Exp $
// Copyright (C)
//   2005-2008 PukiWiki Plus! Team
//   2002-2007 PukiWiki Developers Team
//   2001-2002 Originally written by yu-ji
// License: GPL v2 or (at your option) any later version
//
// General functions
// Plus!NOTE:(policy)not merge official cvs(1.86->1.87)

function is_interwiki($str)
{
	global $InterWikiName;
	return preg_match('/^' . $InterWikiName . '$/', $str);
}

function is_pagename($str)
{
	global $BracketName;

	$is_pagename = (! is_interwiki($str) &&
		  preg_match('/^(?!\/)' . $BracketName . '$(?<!\/$)/', $str) &&
		! preg_match('#(^|/)\.{1,2}(/|$)#', $str));

	if (defined('SOURCE_ENCODING')) {
		switch(SOURCE_ENCODING){
		case 'UTF-8': $pattern =
			'/^(?:[\x00-\x7F]|(?:[\xC0-\xDF][\x80-\xBF])|(?:[\xE0-\xEF][\x80-\xBF][\x80-\xBF]))+$/';
			break;
		case 'EUC-JP': $pattern =
			'/^(?:[\x00-\x7F]|(?:[\x8E\xA1-\xFE][\xA1-\xFE])|(?:\x8F[\xA1-\xFE][\xA1-\xFE]))+$/';
			break;
		}
		if (isset($pattern) && $pattern != '')
			$is_pagename = ($is_pagename && preg_match($pattern, $str));
	}

	return $is_pagename;
}

function is_url($str, $only_http = FALSE)
{
	$scheme = $only_http ? 'https?' : 'https?|ftp|news';
	return preg_match('/^(' . $scheme . ')(:\/\/[-_.!~*\'()a-zA-Z0-9;\/?:\@&=+\$,%#]*)$/', $str);
}

// If the page exists
function is_page($page, $clearcache = FALSE)
{
	if ($clearcache) clearstatcache();
	return file_exists(get_filename($page));
}

function is_cantedit($page)
{
	global $cantedit;
	static $is_cantedit;

	if (! isset($is_cantedit)) {
		foreach($cantedit as $key) {
			$is_cantedit[$key] = TRUE;
		}
	}

	return isset($is_cantedit[$page]);
}

function is_editable($page)
{
	static $is_editable = array();

	if (! isset($is_editable[$page])) {
		$is_editable[$page] = (
			is_pagename($page) &&
			! is_freeze($page) &&
			! is_cantedit($page)
		);
	}

	return $is_editable[$page];
}

function is_freeze($page, $clearcache = FALSE)
{
	global $function_freeze;
	static $is_freeze = array();

	if ($clearcache === TRUE) $is_freeze = array();
	if (isset($is_freeze[$page])) return $is_freeze[$page];

	if (! $function_freeze || ! is_page($page)) {
		$is_freeze[$page] = FALSE;
		return FALSE;
	} else {
		$fp = fopen(get_filename($page), 'rb') or
			die('is_freeze(): fopen() failed: ' . htmlspecialchars($page));
		// flock($fp, LOCK_SH) or die('is_freeze(): flock() failed');
		@flock($fp, LOCK_SH);
		rewind($fp);
		$buffer = fgets($fp, 9);
		// flock($fp, LOCK_UN) or die('is_freeze(): flock() failed');
		@flock($fp, LOCK_UN);
		fclose($fp) or die('is_freeze(): fclose() failed: ' . htmlspecialchars($page));

		$is_freeze[$page] = ($buffer != FALSE && rtrim($buffer, "\r\n") == '#freeze');
		return $is_freeze[$page];
	}
}

// Handling $non_list
// $non_list will be preg_quote($str, '/') later.
function check_non_list($page = '')
{
	global $non_list;
	static $regex;

	if (! isset($regex)) $regex = '/' . $non_list . '/';

	return preg_match($regex, $page);
}

// Auto template
function auto_template($page)
{
	global $auto_template_func, $auto_template_rules;

	if (! $auto_template_func) return '';

	$body = '';
	$matches = array();
	foreach ($auto_template_rules as $rule => $template) {
		$rule_pattrn = '/' . $rule . '/';

		if (! preg_match($rule_pattrn, $page, $matches)) continue;

		$template_page = preg_replace($rule_pattrn, $template, $page);
		if (! is_page($template_page)) continue;

		$body = get_source($template_page, TRUE, TRUE);

		// Remove fixed-heading anchors
		$body = preg_replace('/^(\*{1,3}.*)\[#[A-Za-z][\w-]+\](.*)$/m', '$1$2', $body);

		// Remove '#freeze'
		$body = preg_replace('/^#freeze\s*$/m', '', $body);

		$count = count($matches);
		for ($i = 0; $i < $count; $i++)
			$body = str_replace('$' . $i, $matches[$i], $body);

		break;
	}
	return $body;
}

// Expand search words
function get_search_words($words, $do_escape = FALSE)
{
	static $init, $mb_convert_kana, $pre, $post, $quote = '/';

	if (! isset($init)) {
		// function: mb_convert_kana() is for Japanese code only
		if (LANG == 'ja' && function_exists('mb_convert_kana')) {
			$mb_convert_kana = create_function('$str, $option',
				'return mb_convert_kana($str, $option, SOURCE_ENCODING);');
		} else {
			$mb_convert_kana = create_function('$str, $option',
				'return $str;');
		}
		if (SOURCE_ENCODING == 'EUC-JP') {
			// Perl memo - Correct pattern-matching with EUC-JP
			// http://www.din.or.jp/~ohzaki/perl.htm#JP_Match (Japanese)
			$pre  = '(?<!\x8F)';
			$post =	'(?=(?:[\xA1-\xFE][\xA1-\xFE])*' . // JIS X 0208
				'(?:[\x00-\x7F\x8E\x8F]|\z))';     // ASCII, SS2, SS3, or the last
		} else {
			$pre = $post = '';
		}
		$init = TRUE;
	}

	if (! is_array($words)) $words = array($words);

	// Generate regex for the words
	$regex = array();
	foreach ($words as $word) {
		$word = trim($word);
		if ($word == '') continue;

		// Normalize: ASCII letters = to single-byte. Others = to Zenkaku and Katakana
		$word_nm = $mb_convert_kana($word, 'aKCV');
		$nmlen   = mb_strlen($word_nm, SOURCE_ENCODING);

		// Each chars may be served ...
		$chars = array();
		for ($pos = 0; $pos < $nmlen; $pos++) {
			$char = mb_substr($word_nm, $pos, 1, SOURCE_ENCODING);

			// Just normalized one? (ASCII char or Zenkaku-Katakana?)
			$or = array(preg_quote($do_escape ? htmlspecialchars($char) : $char, $quote));
			if (strlen($char) == 1) {
				// An ASCII (single-byte) character
				foreach (array(strtoupper($char), strtolower($char)) as $_char) {
					if ($char != '&') $or[] = preg_quote($_char, $quote); // As-is?
					$ascii = ord($_char);
					$or[] = sprintf('&#(?:%d|x%x);', $ascii, $ascii); // As an entity reference?
					$or[] = preg_quote($mb_convert_kana($_char, 'A'), $quote); // As Zenkaku?
				}
			} else {
				// NEVER COME HERE with mb_substr(string, start, length, 'ASCII')
				// A multi-byte character
				$or[] = preg_quote($mb_convert_kana($char, 'c'), $quote); // As Hiragana?
				$or[] = preg_quote($mb_convert_kana($char, 'k'), $quote); // As Hankaku-Katakana?
			}
			$chars[] = '(?:' . join('|', array_unique($or)) . ')'; // Regex for the character
		}

		$regex[$word] = $pre . join('', $chars) . $post; // For the word
	}

	return $regex; // For all words
}

// 'Search' main function
function do_search($word, $type = 'AND', $non_format = FALSE, $base = '')
{
	global $script, $whatsnew, $non_list, $search_non_list;
 	global $search_auth, $show_passage, $search_word_color, $ajax;
//	global $_msg_andresult, $_msg_orresult, $_msg_notfoundresult;
	global $_string;

	$retval = array();

	$b_type = ($type == 'AND'); // AND:TRUE OR:FALSE
	$keys = get_search_words(preg_split('/\s+/', $word, -1, PREG_SPLIT_NO_EMPTY));
	foreach ($keys as $key=>$value)
		$keys[$key] = '/' . $value . '/S';

	$pages = get_existpages();

	// Avoid
	if ($base != '') {
		$pages = preg_grep('/^' . preg_quote($base, '/') . '/S', $pages);
	}
	if (! $search_non_list) {
		$pages = array_diff($pages, preg_grep('/' . $non_list . '/S', $pages));
	}
	$pages = array_flip($pages);
	unset($pages[$whatsnew]);

	// SAFE_MODE の場合は、コンテンツ管理者以上のみ、カテゴリページ(:)も検索可能
	$role_adm_contents = (auth::check_role('safemode')) ? auth::check_role('role_adm_contents') : FALSE;

	$count = count($pages);
	foreach (array_keys($pages) as $page) {
		$b_match = FALSE;

		// Search hidden for page name
		if (substr($page, 0, 1) == ':' && $role_adm_contents) {
			unset($pages[$page]);
			--$count;
			continue;
		} 

		// Search for page name
		if (! $non_format) {
			foreach ($keys as $key) {
				$b_match = preg_match($key, $page);
				if ($b_type xor $b_match) break; // OR
			}
			if ($b_match) continue;
		}

		// Search auth for page contents
		if ($search_auth && ! check_readable($page, false, false)) {
			unset($pages[$page]);
			--$count;
			continue;
		}

		// Search for page contents
		foreach ($keys as $key) {
			$b_match = preg_match($key, get_source($page, TRUE, TRUE));
			if ($b_match xor $b_type) break; // OR
		}
		if ($b_match) continue;

		unset($pages[$page]); // Miss
	}

	unset($role_adm_contents);
	if ($non_format) return array_keys($pages);

	$r_word = rawurlencode($word);
	$s_word = htmlspecialchars($word);
	if (empty($pages))
		return str_replace('$1', $s_word, $_string['notfoundresult']);

	ksort($pages, SORT_STRING);

	$retval = '<ul>' . "\n";
	foreach (array_keys($pages) as $page) {
		$r_page  = rawurlencode($page);
		$s_page  = htmlspecialchars($page);
		$passage = $show_passage ? ' ' . get_passage(get_filetime($page)) : '';
		if ($search_word_color) {
			$uri = get_page_uri($page, 'word='.$r_word);
			if ($ajax && UA_PROFILE == 'default') {
				$pre = $script . '?' . 'cmd=preview&amp;page=' . $r_page . '&amp;word=' . $r_word;
				$pre = ' onmouseover="showGlossaryPopup(' . "'" . $pre . "'" . ',event,0.2);" onmouseout="hideGlossaryPopup();"';
			} else {
				$pre = '';
			}
		} else {
			$uri = get_page_uri($page);
			$pre = '';
		}
		$retval .= ' <li><a href="' . $uri . '"' . $pre . '>' . $s_page . '</a>' . $passage . '</li>' . "\n";
	}
	$retval .= '</ul>' . "\n";

	$retval .= str_replace('$1', $s_word, str_replace('$2', count($pages),
		str_replace('$3', $count, $b_type ? $_string['andresult'] : $_string['orresult'])));

	return $retval;
}

// Argument check for program
function arg_check($str)
{
	global $vars;
	return isset($vars['cmd']) && (strpos($vars['cmd'], $str) === 0);
}

// Encode page-name
function encode($str)
{
	$str = strval($str);
	return ($str == '') ? '' : strtoupper(bin2hex($str));
	// Equal to strtoupper(join('', unpack('H*0', $str)));
	// But PHP 4.3.10 says 'Warning: unpack(): Type H: outside of string in ...'
}

// Decode page name
function decode($str)
{
	return hex2bin($str);
}

// Inversion of bin2hex()
function hex2bin($hex_string)
{
	// preg_match : Avoid warning : pack(): Type H: illegal hex digit ...
	// (string)   : Always treat as string (not int etc). See BugTrack2/31
	return preg_match('/^[0-9a-f]+$/i', $hex_string) ?
		pack('H*', (string)$hex_string) : $hex_string;
}

// Remove [[ ]] (brackets)
function strip_bracket($str)
{
	$match = array();
	if (preg_match('/^\[\[(.*)\]\]$/', $str, $match)) {
		return $match[1];
	} else {
		return $str;
	}
}

// Create list of pages
function page_list($pages, $cmd = 'read', $withfilename = FALSE)
{
	global $script, $list_index;
	global $pagereading_enable;
	global $_string;

	// ソートキーを決定する。 ' ' < '[a-zA-Z]' < 'zz'という前提。
	$symbol = ' ';
	$other = 'zz';

	$retval = '';

	if($pagereading_enable) {
		mb_regex_encoding(SOURCE_ENCODING);
		$readings = get_readings($pages);
	}

	$list = $matches = array();

	foreach($pages as $file=>$page) {
		$s_page  = htmlspecialchars($page, ENT_QUOTES);
		$passage = get_pg_passage($page);

		// Shrink URI for read
		if ($cmd == 'read') {
			$url = get_page_uri($page);
		} else {
			$url = get_resolve_uri($cmd,$page);
		}

		$str = '   <li><a href="' . $url . '">' .
			$s_page . '</a>' . $passage;

		if ($withfilename) {
			$s_file = htmlspecialchars($file);
			$str .= "\n" . '    <ul><li>' . $s_file . '</li></ul>' .
				"\n" . '   ';
		}
		$str .= '</li>';

		// WARNING: Japanese code hard-wired
		if($pagereading_enable) {
			if(mb_ereg('^([A-Za-z])', mb_convert_kana($page, 'a'), $matches)) {
				$head = $matches[1];
			} elseif (isset($readings[$page]) && mb_ereg('^([ァ-ヶ])', $readings[$page], $matches)) { // here
				$head = $matches[1];
			} elseif (mb_ereg('^[ -~]|[^ぁ-ん亜-熙]', $page)) { // and here
				$head = $symbol;
			} else {
				$head = $other;
			}
		} else {
			$head = (preg_match('/^([A-Za-z])/', $page, $matches)) ? $matches[1] :
				(preg_match('/^([ -~])/', $page) ? $symbol : $other);
		}

		$list[$head][$page] = $str;
	}
	ksort($list);

	$cnt = 0;
	$arr_index = array();
	$retval .= '<ul>' . "\n";
	foreach ($list as $head=>$pages) {
		if ($head === $symbol) {
			$head = $_string['symbol'];
		} else if ($head === $other) {
			$head = $_string['other'];
		}

		if ($list_index) {
			++$cnt;
			$arr_index[] = '<a id="top_' . $cnt .
				'" href="#head_' . $cnt . '"><strong>' .
				$head . '</strong></a>';
			$retval .= ' <li><a id="head_' . $cnt . '" href="#top_' . $cnt .
				'"><strong>' . $head . '</strong></a>' . "\n" .
				'  <ul>' . "\n";
		}
		ksort($pages);
		$retval .= join("\n", $pages);
		if ($list_index)
			$retval .= "\n  </ul>\n </li>\n";
	}
	$retval .= '</ul>' . "\n";
	if ($list_index && $cnt > 0) {
		$top = array();
		while (! empty($arr_index))
			$top[] = join(' | ' . "\n", array_splice($arr_index, 0, 16)) . "\n";

		$retval = '<div id="top" style="text-align:center">' . "\n" .
			join('<br />', $top) . '</div>' . "\n" . $retval;
	}
	return $retval;
}

// Show text formatting rules
function catrule()
{
	global $rule_page;

	if (! is_page($rule_page)) {
		return '<p>Sorry, page \'' . htmlspecialchars($rule_page) .
			'\' unavailable.</p>';
	} else {
		return convert_html(get_source($rule_page));
	}
}

// Show (critical) error message
function die_message($msg)
{
	global $skin_file;
	$title = $page = 'Runtime error';
	$body = <<<EOD
<h3>Runtime error</h3>
<strong>Error message : $msg</strong>
EOD;

	// @miko:recover: $trackback is unused.
	global $trackback;
	$trackback = 0;
	// @miko

	pkwk_common_headers();
	if(defined('SKIN_FILE') && file_exists(SKIN_FILE) && is_readable(SKIN_FILE)) {
		catbody($title, $page, $body);
	} elseif ($skin_file != '' && file_exists($skin_file) && is_readable($skin_file)) {
		define('SKIN_FILE', $skin_file);
		catbody($title, $page, $body);
	} else {
		header('Content-Type: text/html; charset=euc-jp');
		print <<<EOD
<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>
 <head>
  <title>$title</title>
  <meta http-equiv="content-type" content="text/html; charset=euc-jp">
 </head>
 <body>
 $body
 </body>
</html>
EOD;
	}
	exit;
}

function die_msg($msg) { die($msg); }

// Have the time (as microtime)
function getmicrotime()
{
	list($usec, $sec) = explode(' ', microtime());
	return ((float)$sec + (float)$usec);
}

// Elapsed time by second
//define('MUTIME', getmicrotime());
function elapsedtime()
{
	$at_the_microtime = MUTIME;
	return sprintf('%01.03f', getmicrotime() - $at_the_microtime);
}

// Get the date
function get_date($format, $timestamp = NULL)
{
	$esc = '';
	$i = strlen(ZONE) - 1;
	for($j=0; $j<$i; $j++) { $esc .= '\\'; }

	$format = preg_replace('/(?<!'.$esc.')T/',
		preg_replace('/(.)/', $esc.'$1', ZONE), $format);

	//$format = preg_replace('/(?<!\\\)T/',
	//	preg_replace('/(.)/', '\\\$1', ZONE), $format);

	$time = ZONETIME + (($timestamp !== NULL) ? $timestamp : UTIME);

	$str = gmdate($format, $time);
	if (ZONETIME == 0) return $str;

        $zonetime = get_zonetime_offset(ZONETIME);
	return str_replace('+0000', $zonetime, $str);
}

function get_zonetime_offset($zonetime)
{
	$pm = ($zonetime < 0) ? '-' : '+';
	$zonetime = abs($zonetime);
	(int)$h = $zonetime / 3600;
	$m = $zonetime - ($h * 3600);
	return sprintf('%s%02d%02d', $pm,$h,$m);
}

// Format date string
function format_date($val, $paren = FALSE)
{
	global $date_format, $time_format, $weeklabels;

	$val += ZONETIME;

        $date = gmdate($date_format, $val) .
                ' (' . $weeklabels[gmdate('w', $val)] . ') ' .
                gmdate($time_format, $val);

	return $paren ? '(' . $date . ')' : $date;
}

// Get short pagename(last token without '/')
function get_short_pagename($fullpagename)
{
	$pagestack = explode('/', $fullpagename);
	return array_pop($pagestack);
}

// Get short string of the passage, 'N seconds/minutes/hours/days/years ago'
function get_passage($time, $paren = TRUE)
{
	static $units = array('m'=>60, 'h'=>24, 'd'=>1);

	$time = max(0, (UTIME - $time) / 60); // minutes

	foreach ($units as $unit=>$card) {
		if ($time < $card) break;
		$time /= $card;
	}
	$time = floor($time) . $unit;

	return $paren ? '(' . $time . ')' : $time;
}

// Hide <input type="(submit|button|image)"...>
function drop_submit($str)
{
	return preg_replace('/<input([^>]+)type="(submit|button|image)"/i',
		'<input$1type="hidden"', $str);
}

function get_glossary_pattern(& $pages, $min_len = -1)
{
	global $WikiName, $autoglossary, $nowikiname;

	$config = &new Config('Glossary');
	$config->read();
	$ignorepages      = $config->get('IgnoreList');
	$forceignorepages = $config->get('ForceIgnoreList');
	unset($config);
	$auto_pages = array_merge($ignorepages, $forceignorepages);

	if ($min_len == -1) {
		$min_len = $autoglossary;   // set $autoglossary, when omitted.
	}

	foreach ($pages as $page)
		if (preg_match('/^' . $WikiName . '$/', $page) ?
		    $nowikiname : mb_strlen($page) >= $min_len)
			$auto_pages[] = $page;

	if (empty($auto_pages)) {
		return array('(?!)', 'PukiWiki', 'PukiWiki');
	} else {
		$auto_pages = array_unique($auto_pages);
		sort($auto_pages, SORT_STRING);

		$auto_pages_a = array_values(preg_grep('/^[A-Z]+$/i', $auto_pages));
		$auto_pages   = array_values(array_diff($auto_pages,  $auto_pages_a));

		$result   = generate_trie_regex($auto_pages);
		$result_a = generate_trie_regex($auto_pages_a);
	}
	return array($result, $result_a, $forceignorepages);
}

// Generate AutoLink patterns (thx to hirofummy)
function get_autolink_pattern(& $pages, $min_len = -1)
{
	global $WikiName, $autolink, $nowikiname;

	$config = &new Config('AutoLink');
	$config->read();
	$ignorepages      = $config->get('IgnoreList');
	$forceignorepages = $config->get('ForceIgnoreList');
	unset($config);
	$auto_pages = array_merge($ignorepages, $forceignorepages);

	if ($min_len == -1) {
		$min_len = $autolink;   // set $autolink, when omitted.
	}

	foreach ($pages as $page)
		if (preg_match('/^' . $WikiName . '$/', $page) ?
		    $nowikiname : strlen($page) >= $min_len)
			$auto_pages[] = $page;

	if (empty($auto_pages)) {
		$result = $result_a = $nowikiname ? '(?!)' : $WikiName;
	} else {
		$auto_pages = array_unique($auto_pages);
		sort($auto_pages, SORT_STRING);

		$auto_pages_a = array_values(preg_grep('/^[A-Z]+$/i', $auto_pages));
		$auto_pages   = array_values(array_diff($auto_pages,  $auto_pages_a));

		$result   = generate_trie_regex($auto_pages);
		$result_a = generate_trie_regex($auto_pages_a);
	}
	return array($result, $result_a, $forceignorepages);
}


// Generate one compact regex for quick reTRIEval,
// that just matches with all $array values.
//
// USAGE:
//   sort($array, SORT_STRING); // Keys are replaced, as we had expected
//   $regex = generate_trie_regex($array);
// ARGUMENTS:
//   $array  : (_sorted_ _string_ array)
//     array_keys($array) MUST BE _continuous integers started with 0_.
//     All $array values MUST BE strings.
//     Passing the reference, of the $array here, will save the memories,
//     from flood of recursive call.
//   $offset : (int) $array[$offset] is the first value to check
//   $sentry : (int) $array[$sentry - 1] is the last value to check  
//   $pos    : (int) Position of the letter to start checking. (0 = the first letter)
// REFERENCE:
//   http://en.wikipedia.org/wiki/Trie
function generate_trie_regex(& $array, $offset = 0, $sentry = NULL, $pos = 0)
{
	if (empty($array)) return '(?!)'; // Zero
	if ($sentry === NULL) $sentry = count($array);

	// Too short. Skip this
	$skip = ($pos >= mb_strlen($array[$offset]));
	if ($skip) ++$offset;

	// Generate regex for each value
	$regex = '';
	$index = $offset;
	$multi = FALSE;
	while ($index < $sentry) {
		if ($index != $offset) {
			$multi = TRUE;
			$regex .= '|'; // OR
		}

		// Get one character from left side of the value
		$char = mb_substr($array[$index], $pos, 1);

		// How many continuous keys have the same letter
		// at the same position?
		for ($i = $index; $i < $sentry; $i++)
			if (mb_substr($array[$i], $pos, 1) != $char) break;

		if ($index < ($i - 1)) {
			// Some more keys found
			// Recurse
			$regex .= str_replace(' ', '\\ ', preg_quote($char, '/')) .
				generate_trie_regex($array, $index, $i, $pos + 1);
		} else {
			// Not found
			$regex .= str_replace(' ', '\\ ',
				preg_quote(mb_substr($array[$index], $pos), '/'));
		}
		$index = $i;
	}

	if ($skip || $multi) $regex = '(?:' . $regex . ')';
	if ($skip) $regex .= '?'; // Match for $pages[$offset - 1]

	return $regex;
}

// Compat
function get_autolink_pattern_sub(& $pages, $start, $end, $pos)
{
	 return generate_trie_regex($pages, $start, $end, $pos);
}

// Load/get autoalias pairs
function get_autoaliases($word = '')
{
	global $autobasealias;
	static $pairs;
	if (! isset($pairs)) {
		$pairs = get_autoaliases_from_aliaspage();
		if ($autobasealias) {
			$pairs = array_merge($pairs, get_autoaliases_from_autobasealias());
		}
	}

	// An array: All pairs
	if ($word === '') return $pairs;

	// A string: Seek the pair
	return isset($pairs[$word]) ? $pairs[$word] : array();
}

// Load/get pairs of AutoBaseAlias
function get_autoaliases_from_autobasealias()
{
	static $paris;
	if (! isset($pairs)) {
		$data = file_get_contents(CACHE_DIR . PKWK_AUTOBASEALIAS_CACHE);
		$pairs = unserialize($data);
	}
	return $pairs;
}

// Load/get setting pairs from AutoAliasName
function get_autoaliases_from_aliaspage()
{
	global $aliaspage, $autoalias_max_words;
	static $pairs;

	if (! isset($pairs)) {
		$pairs = array();
		$pattern = <<<EOD
\[\[                # open bracket
((?:(?!\]\]).)+)>   # (1) alias name
((?:(?!\]\]).)+)    # (2) alias link
\]\]                # close bracket
EOD;

		$postdata = get_source($aliaspage, TRUE, TRUE);
		$matches = array();
		$count = 0;
		$max   = max($autoalias_max_words, 0);
		if (preg_match_all('/' . $pattern . '/x', $postdata, $matches, PREG_SET_ORDER)) {
			foreach($matches as $key => $value) {
				if ($count == $max) break;
				$name = trim($value[1]);
				if (! isset($pairs[$name])) {
					$paris[$name] = array();
				} 
				++$count;
				$pairs[$name][] = trim($value[2]);
				unset($matches[$key]);
			}
		}
		foreach (array_keys($pairs) as $name) {
			$pairs[$name] = array_unique($pairs[$name]);
		}
	}
	return $pairs;
}

// Load/get setting pairs from Glossary
function get_autoglossaries($word = '')
{
	global $glossarypage, $autoglossary_max_words;
	static $pairs;

	if (! isset($pairs)) {
		$pairs = array();
		$pattern = '/^[:|]([^|]+)\|([^|]+)\|?$/';
		$postdata = get_source($glossarypage);
		$matches = array();
		$count = 0;
		$max   = max($autoglossary_max_words, 0);
		foreach ($postdata as $line) {
			if ($count == $max) break;
			if (preg_match($pattern, $line, $matches)) {
				$name = trim($matches[1]);
				if (!isset($pairs[$name])) {
					++$count;
					$pairs[$name] = TRUE;
				}
			}
		}
	}

	// An array: All pairs
	if ($word === '') return $pairs;

	// A string: Seek the pair
	return isset($pairs[$word]) ? $pairs[$word]:'';
}

// Get absolute-URI of this script
function get_script_uri($init_uri = '')
{
	global $script_directory_index;
	static $script;

	if ($init_uri == '') {
		// Get
		if (isset($script)) return $script;
		$script = get_script_absuri();
		return $script;
	}

	// Set manually
	if (isset($script)) die_message('$script: Already init');
	if ($init_uri != './'  && ! is_url($init_uri, TRUE)) die_message('$script: Invalid URI');
	$script = $init_uri;

	// Cut filename or not
	if (isset($script_directory_index)) {
		if (! file_exists($script_directory_index))
			die_message('Directory index file not found: ' .
				htmlspecialchars($script_directory_index));
		$matches = array();
		if (preg_match('#^(.+/)' . preg_quote($script_directory_index, '#') . '$#',
			$script, $matches)) $script = $matches[1];
	}

	return $script;
}

// Get absolute-URI of this script
function get_script_absuri()
{
	global $script_abs, $script_directory_index;
	global $script;
	static $uri;

	// Get
	if (isset($uri)) return $uri;

	// Set automatically
	$msg     = 'get_script_absuri() failed: Please set [$script or $script_abs] at INI_FILE manually';

	$uri  = (SERVER_PORT == 443 ) ? 'https://' : 'http://'; // scheme
	$uri .= SERVER_NAME; // host
	$uri .= (SERVER_PORT == 80 || SERVER_PORT == 443) ? '' : ':' . SERVER_PORT;  // port

	// SCRIPT_NAME が'/'で始まっていない場合(cgiなど) REQUEST_URIを使ってみる
	$path    = SCRIPT_NAME;
	if ($path{0} != '/') {
		$check_base_url = (isset($script_abs)) ? array($script, $script_abs) : array($script);

		if (! isset($_SERVER['REQUEST_URI']) || $_SERVER['REQUEST_URI']{0} != '/') {
			foreach($check_base_url as $x) {
				if (is_url($x, true)) {
					$uri = $x;
					return $uri;
				}
			}
			die_message($msg);
		}

		// REQUEST_URIをパースし、path部分だけを取り出す
		$parse_url = parse_url($uri . $_SERVER['REQUEST_URI']);
		if (! isset($parse_url['path']) || $parse_url['path']{0} != '/') {
			foreach($check_base_url as $x) {
				if (is_url($x, true)) {
					$uri = $x;
					return $uri;
				}
			}
			die_message($msg);
		}

		$path = $parse_url['path'];
	}
	$uri .= $path;

	if (! is_url($uri, true) && php_sapi_name() == 'cgi') {
		die_message($msg);
	}
	unset($msg);

	// Cut filename or not
	if (isset($script_directory_index)) {
		if (! file_exists($script_directory_index))
			die_message('Directory index file not found: ' .
			htmlspecialchars($script_directory_index));
		$matches = array();
		if (preg_match('#^(.+/)' . preg_quote($script_directory_index, '#') . '$#',
			$uri, $matches)) $uri = $matches[1];
	}

        return $uri;
}

function get_resolve_uri($cmd='', $page='', $query='', $abs=0, $location=0)
{
	global $script, $absolute_uri;

	if ($abs) {
		$ret = get_script_absuri();
	} else {
		$ret = $absolute_uri ? get_script_absuri() : $script;
	}
	// $ret = ($abs) ? get_script_absuri() : ($absolute_uri ? get_script_absuri() : $script);

	$flag = '?';
	$amp = ($location) ? '&' : '&amp;';
	$page_pref = '';

	if (! empty($cmd)) {
		$ret .= $flag.'cmd='.$cmd;
		$flag = $amp;
		$page_pref = 'page=';
	}

	if (! empty($page)) {
		$ret .= $flag.$page_pref.rawurlencode($page);
		$flag = $amp;
	}

	if (is_array($query)) {
		foreach($query as $key=>$val) {
			$ret .= $flag . $key . '='. rawurlencode($val);
			$flag = $amp;
		}
	} else
	if (! empty($query)) {
		$ret .= $flag . $query;
	}

	unset($flag, $page_pref);
	return $ret;
}

function get_resolve_absuri($cmd='', $page='', $query='')
{
	//$args = func_get_args();
	//$args[] = 1;
	//return call_user_func_array('get_resolve_uri',$args);
	return get_resolve_uri($cmd,$page,$query,1);
}

// get_resolve_uri
function get_page_uri($page, $query='')
{
	return get_resolve_uri('', $page, $query);
}

function get_page_absuri($page, $query='')
{
	return get_resolve_uri('', $page, $query,1);
}

function get_page_location_uri($page='', $query='')
{
	return get_resolve_uri('', $page, $query, 1, 1);
}

// get_resolve_uri
function get_location_uri($cmd='', $page='', $query='')
{
	return get_resolve_uri($cmd, $page, $query, 1, 1);
}

// Remove null(\0) bytes from variables
//
// NOTE: PHP had vulnerabilities that opens "hoge.php" via fopen("hoge.php\0.txt") etc.
// [PHP-users 12736] null byte attack
// http://ns1.php.gr.jp/pipermail/php-users/2003-January/012742.html
//
// 2003-05-16: magic quotes gpcの復元処理を統合
// 2003-05-21: 連想配列のキーはbinary safe
//
function input_filter($param)
{
	static $magic_quotes_gpc = NULL;
	if ($magic_quotes_gpc === NULL)
	    $magic_quotes_gpc = get_magic_quotes_gpc();

	if (is_array($param)) {
		return array_map('input_filter', $param);
	} else {
		$result = str_replace("\0", '', $param);
		if ($magic_quotes_gpc) $result = stripslashes($result);
		return $result;
	}
}

// Compat for 3rd party plugins. Remove this later
function sanitize($param) {
	return input_filter($param);
}

// Explode Comma-Separated Values to an array
function csv_explode($separator, $string)
{
	$retval = $matches = array();

	$_separator = preg_quote($separator, '/');
	if (! preg_match_all('/("[^"]*(?:""[^"]*)*"|[^' . $_separator . ']*)' .
	    $_separator . '/', $string . $separator, $matches))
		return array();

	foreach ($matches[1] as $str) {
		$len = strlen($str);
		if ($len > 1 && $str{0} == '"' && $str{$len - 1} == '"')
			$str = str_replace('""', '"', substr($str, 1, -1));
		$retval[] = $str;
	}
	return $retval;
}

// Implode an array with CSV data format (escape double quotes)
function csv_implode($glue, $pieces)
{
	$_glue = ($glue != '') ? '\\' . $glue{0} : '';
	$arr = array();
	foreach ($pieces as $str) {
		if (ereg('[' . $_glue . '"' . "\n\r" . ']', $str))
			$str = '"' . str_replace('"', '""', $str) . '"';
		$arr[] = $str;
	}
	return join($glue, $arr);
}

//// Compat ////

// is_a --  Returns TRUE if the object is of this class or has this class as one of its parents
// (PHP 4 >= 4.2.0)
if (! function_exists('is_a')) {

	function is_a($class, $match)
	{
		if (empty($class)) return FALSE; 

		$class = is_object($class) ? get_class($class) : $class;
		if (strtolower($class) == strtolower($match)) {
			return TRUE;
		} else {
			return is_a(get_parent_class($class), $match);	// Recurse
		}
	}
}

// array_fill -- Fill an array with values
// (PHP 4 >= 4.2.0)
if (! function_exists('array_fill')) {

	function array_fill($start_index, $num, $value)
	{
		$ret = array();
		while ($num-- > 0) $ret[$start_index++] = $value;
		return $ret;
	}
}

// md5_file -- Calculates the md5 hash of a given filename
// (PHP 4 >= 4.2.0)
if (! function_exists('md5_file')) {

	function md5_file($filename)
	{
		if (! file_exists($filename)) return FALSE;

		$fd = fopen($filename, 'rb');
		if ($fd === FALSE ) return FALSE;
		$data = fread($fd, filesize($filename));
		fclose($fd);
		return md5($data);
	}
}

// sha1 -- Compute SHA-1 hash
// (PHP 4 >= 4.3.0, PHP5)
if (! function_exists('sha1')) {
	if (extension_loaded('mhash')) {
		function sha1($str)
		{
			return bin2hex(mhash(MHASH_SHA1, $str));
		}
	}
}
?>
