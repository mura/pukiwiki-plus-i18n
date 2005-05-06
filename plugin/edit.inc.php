<?php
// PukiWiki - Yet another WikiWikiWeb clone.
// $Id: edit.inc.php,v 1.33.19 2005/05/06 17:29:02 miko Exp $
//
// Edit plugin
// cmd=edit

// Remove #freeze written by hand
define('PLUGIN_EDIT_FREEZE_REGEX', '/^(?:#freeze(?!\w)\s*)+/im');

function plugin_edit_action()
{
	global $vars, $_title_edit, $load_template_func;

	if (PKWK_READONLY) die_message('PKWK_READONLY prohibits editing');

	$page = isset($vars['page']) ? $vars['page'] : '';

	check_editable($page, true, true);

	if (isset($vars['preview']) || ($load_template_func && isset($vars['template']))) {
		return plugin_edit_preview();
	} else if (isset($vars['write'])) {
		return plugin_edit_write();
	} else if (isset($vars['cancel'])) {
		return plugin_edit_cancel();
	}

	$source = get_source($page);
	$postdata = $vars['original'] = @join('', $source);
	if (!empty($vars['id']))
	{
		$postdata = plugin_edit_parts($vars['id'],$source);
		if ($postdata === FALSE)
		{
			unset($vars['id']);
			$postdata = $vars['original'];
		}
	}

	if ($postdata == '') $postdata = auto_template($page);

	return array('msg'=>$_title_edit, 'body'=>edit_form($page, $postdata));
}

// Preview
function plugin_edit_preview()
{
	global $vars;
	global $_title_preview, $_msg_preview, $_msg_preview_delete;

	$page = isset($vars['page']) ? $vars['page'] : '';

	// Loading template
	if (isset($vars['template_page']) && is_page($vars['template_page'])) {

		$vars['msg'] = join('', get_source($vars['template_page']));

		// Cut fixed anchors
		$vars['msg'] = preg_replace('/^(\*{1,3}.*)\[#[A-Za-z][\w-]+\](.*)$/m', '$1$2', $vars['msg']);
	}

	$vars['msg'] = preg_replace(PLUGIN_EDIT_FREEZE_REGEX, '', $vars['msg']);
	$postdata = $vars['msg'];

	if (isset($vars['add']) && $vars['add']) {
		if (isset($vars['add_top']) && $vars['add_top']) {
			$postdata  = $postdata . "\n\n" . @join('', get_source($page));
		} else {
			$postdata  = @join('', get_source($page)) . "\n\n" . $postdata;
		}
	}

	$body = "$_msg_preview<br />\n";
	if ($postdata == '')
		$body .= "<strong>$_msg_preview_delete</strong>";
	$body .= "<br />\n";

	if ($postdata) {
		$postdata = make_str_rules($postdata);
		$postdata = explode("\n", $postdata);
		$postdata = drop_submit(convert_html($postdata));
		$body .= '<div id="preview">' . $postdata . '</div>' . "\n";
	}
	$body .= edit_form($page, $vars['msg'], $vars['digest'], FALSE);

	return array('msg'=>$_title_preview, 'body'=>$body);
}

// Inline: Show edit (or unfreeze text) link
function plugin_edit_inline()
{
	static $usage = '&edit(pagename#anchor[[,noicon],nolabel])[{label}];';

//	global $script, $vars, $fixed_heading_anchor_edit;
	global $script, $vars, $fixed_heading_edited;

	if (PKWK_READONLY) return ''; // Show nothing 

	// Arguments
	$args = func_get_args();
	$s_label = array_pop($args); // {label}
	$page    = array_shift($args);
	if($page == NULL) $page = '';
	$_noicon = $_nolabel = FALSE;
	foreach($args as $arg){
		switch($arg){
		case '': break;
		case 'nolabel': $_nolabel = TRUE; break;
		case 'noicon':  $_noicon  = TRUE; break;
		default: return $usage;
		}
	}

	// Separate a page-name and a fixed anchor
	list($s_page, $id, $editable) = anchor_explode($page, TRUE);
	// Default: This one
	if ($s_page == '') $s_page = isset($vars['page']) ? $vars['page'] : '';
	// $s_page fixed
	$isfreeze = is_freeze($s_page);
	$ispage   = is_page($s_page);

	// Paragraph edit enabled or not
	$short = htmlspecialchars('Edit');
	if ($fixed_heading_edited && $editable && $ispage && ! $isfreeze) {
		// Paragraph editing
		$id    = rawurlencode($id);
		$title = htmlspecialchars(sprintf('Edit %s', $page));
		$icon = '<img src="' . IMAGE_DIR . 'paraedit.png' .
			'" width="9" height="9" alt="' .
			$short . '" title="' . $title . '" /> ';
		$class = ' class="anchor_super"';
	} else {
		// Normal editing / unfreeze
		$id    = '';
		if ($isfreeze) {
			$title = 'Edit %s';
			$icon  = 'edit.png';
		} else {
			$title = 'Edit %s';
			$icon  = 'edit.png';
		}
		$title = htmlspecialchars(sprintf($title, $s_page));
		$icon = '<img src="' . IMAGE_DIR . $icon .
			'" width="20" height="20" alt="' .
			$short . '" title="' . $title . '" />';
		$class = '';
	}
	if ($_noicon) $icon = ''; // No more icon
	if ($_nolabel) {
		if (!$_noicon) {
			$s_label = '';     // No label with an icon
		} else {
			$s_label = $short; // Short label without an icon
		}
	} else {
		if ($s_label == '') $s_label = $title; // Rich label with an icon
	}

	// URL
	if ($isfreeze) {
		$url = $script . '?cmd=edit&amp;page=' . rawurlencode($s_page) . $s_id;
	} else {
		if ($id != '') {
			$s_id = '&amp;id=' . $id;
		} else {
			$s_id = '';
		}
		$url = $script . '?cmd=edit&amp;page=' . rawurlencode($s_page) . $s_id;
	}
	$atag  = '<a' . $class . ' href="' . $url . '" title="' . $title . '">';
	static $atags = '</a>';

	if ($ispage) {
		// Normal edit link
		return $atag . $icon . $s_label . $atags;
	} else {
		// Dangling edit link
		return '<span class="noexists">' . $atag . $icon . $atags .
			$s_label . $atag . '?' . $atags . '</span>';
	}
}

// Write, add, or insert new comment
function plugin_edit_write()
{
	global $vars;
	global $_title_collided, $_msg_collided_auto, $_msg_collided, $_title_deleted;
	global $notimeupdate, $_msg_invalidpass;

	$page = isset($vars['page']) ? $vars['page'] : '';
	$retvars = array();

	$vars['msg'] = preg_replace(PLUGIN_EDIT_FREEZE_REGEX,'',$vars['msg']);
	$postdata = $postdata_input = $vars['msg'];

	if (isset($vars['add']) && $vars['add']) {
		if (isset($vars['add_top']) && $vars['add_top']) {
			$postdata  = $postdata . "\n\n" . @join('', get_source($page));
		} else {
			$postdata  = @join('', get_source($page)) . "\n\n" . $postdata;
		}
	} else if (isset($vars['id']) && $vars['id']) {
		$source = preg_split('/([^\n]*\n)/',$vars['original'],-1,PREG_SPLIT_NO_EMPTY|PREG_SPLIT_DELIM_CAPTURE);
		if (plugin_edit_parts($vars['id'],$source,$vars['msg']) !== FALSE) {
			$postdata = $postdata_input = join('',$source);
		} else {
			$postdata = $postdata_input = rtrim($vars['original'])."\n\n".$vars['msg'];
		}
	}

	$oldpagesrc = join('', get_source($page));
	$oldpagemd5 = md5($oldpagesrc);

	if (! isset($vars['digest']) || $vars['digest'] != $oldpagemd5) {
		$vars['digest'] = $oldpagemd5;

		$retvars['msg'] = $_title_collided;
		list($postdata_input, $auto) = do_update_diff($oldpagesrc, $postdata_input, $vars['original']);

		$retvars['body'] = ($auto ? $_msg_collided_auto : $_msg_collided) . "\n";

		if (TRUE) {
			global $do_update_diff_table;
			$retvars['body'] .= $do_update_diff_table;
		}

		unset($vars['id']);	// if update collision, edit all source.
		$retvars['body'] .= edit_form($page, $postdata_input, $oldpagemd5, FALSE);
	}
	else {
		if ($postdata) {
			$notimestamp = ($notimeupdate != 0) && (isset($vars['notimestamp']) && $vars['notimestamp'] != '');
			if($notimestamp && ($notimeupdate == 2) && !pkwk_login($vars['pass'])) {
				// enable only administrator & password error
				$retvars['body']  = "<p><strong>$_msg_invalidpass</strong></p>\n";
				$retvars['body'] .= edit_form($page, $vars['msg'], $vars['digest'], FALSE);
			} else {
				page_write($page, $postdata, $notimestamp);
				pkwk_headers_sent();
				if ($vars['refpage'] != '') {
					if ($vars['id'] != '') {
						header('Location: ' . get_script_uri() . '?' . rawurlencode($vars['refpage'])) . '#' . rawurlencode($vars['id']);
					} else {
						header('Location: ' . get_script_uri() . '?' . rawurlencode($vars['refpage']));
					}
				} else {
					if ($vars['id'] != '') {
						header('Location: ' . get_script_uri() . '?' . rawurlencode($page)) . '#' . rawurlencode($vars['id']);
					} else {
						header('Location: ' . get_script_uri() . '?' . rawurlencode($page));
					}
				}
				exit;
			}
		} else {
			page_write($page, $postdata);
			$retvars['msg'] = $_title_deleted;
			$retvars['body'] = str_replace('$1', htmlspecialchars($page), $_title_deleted);
			tb_delete($page);
		}
	}

	return $retvars;
}

// Cancel (Back to the page / Escape edit page)
function plugin_edit_cancel()
{
	global $vars;
	pkwk_headers_sent();
	header('Location: ' . get_script_uri() . '?' . rawurlencode($vars['page']));
	exit;
}

// Replace part of source
function plugin_edit_parts($id,&$source,$postdata='')
{
	$postdata = rtrim($postdata)."\n";
	$heads = preg_grep('/^\*{1,3}.+$/',$source);
	$heads[count($source)] = ''; // sentinel
	while (list($start,$line) = each($heads))
	{
		if (preg_match("/\[#$id\]/",$line))
		{
			list($end,$line) = each($heads);
			return join('',array_splice($source,$start,$end - $start,$postdata));
		}
	}
	return FALSE;
}

?>