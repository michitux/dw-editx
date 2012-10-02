<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Danny Lin <danny.0838@gmail.com>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

class action_plugin_editx extends DokuWiki_Action_Plugin {

    /**
     * register the eventhandlers
     */
    function register(&$contr) {
        $contr->register_hook('TPL_ACT_RENDER', 'BEFORE', $this, '_prepend_to_edit', array());
        $contr->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, '_handle_act', array());
        $contr->register_hook('TPL_ACT_UNKNOWN', 'BEFORE', $this, '_handle_tpl_act', array());
        $contr->register_hook('IO_WIKIPAGE_READ', 'AFTER', $this, '_handle_read', array());
        $contr->register_hook('PARSER_CACHE_USE', 'BEFORE', $this, '_handle_cache', array());
    }

    /**
     * main hooks
     */ 
    function _prepend_to_edit(&$event, $param) {
        global $ID;
        if ($event->data != 'edit') return;
        if (!$this->_auth_check_all($ID)) return;
        $link = html_wikilink($ID.'?do=editx');
        $intro = $this->locale_xhtml('intro');
        $intro = str_replace( '@LINK@', $link, $intro );
        print $intro;
    }

    function _handle_act(&$event, $param) {
        if($event->data != 'editx') return;
        $event->preventDefault();
    }

    function _handle_tpl_act(&$event, $param) {
        if($event->data != 'editx') return;
        $event->preventDefault();

        switch ($_REQUEST['work']) {
            case 'rename':
                $opts['oldpage'] = cleanID($_REQUEST['oldpage']);
                $opts['newpage'] = cleanID($_REQUEST['newpage']);
                $opts['summary'] = $_REQUEST['summary'];
                $opts['nr'] = $_REQUEST['rp_nr'];
                $opts['confirm'] = $_REQUEST['rp_confirm'];
                $this->_rename_page($opts);
                break;
            case 'delete':
                $opts['oldpage'] = cleanID($_REQUEST['oldpage']);
                $opts['summary'] = $_REQUEST['summary'];
                $opts['purge'] = $_REQUEST['dp_purge'];
                $opts['confirm'] = $_REQUEST['dp_confirm'];
                $this->_delete_page($opts);
                break;
            default:
                $this->_print_form();
                break;
        }
    }

    function _handle_read(Doku_Event $event, $param) {
        static $stack = array();
        // handle only reads of the current revision
        if ($event->data[3]) return;

        $id = $event->data[2];
        if ($event->data[1]) $id = $event->data[1].':'.$id;
        if (isset($stack[$id])) return;
        $meta = p_get_metadata($id, 'plugin_editx', METADATA_DONT_RENDER);
        if ($meta && isset($meta['moves'])) {
            $stack[$id] = true;
            $event->result = $this->_rewrite_content($event->result, $id, $meta['moves']);
            $file = wikiFN($id, '', false);
            if (is_writable($file)) {
                saveWikiText($id,$event->result,$this->getLang('rewrite_summary'));
                unset($meta['moves']);
                p_set_metadata($id, array('plugin_editx' => $meta), false, true);
            } else { // FIXME: print error here or fail silently?
                msg('Error: Page '.hsc($id).' needs to be rewritten because of page renames but is not writable.', -1);
            }
            unset($stack[$id]);
        }
    }

    function _handle_cache(Doku_Event $event, $param) {
        /** @var $cache cache_parser */
        $cache = $event->data;
        $id = $cache->page;
        if ($id) {
            $meta = p_get_metadata($id, 'plugin_editx', METADATA_DONT_RENDER);
            if ($meta && isset($meta['moves'])) {
                $file = wikiFN($id, '', false);
                if (is_writable($file))
                    $cache->depends['purge'] = true;
                else // FIXME: print error here or fail silently?
                    msg('Error: Page '.hsc($id).' needs to be rewritten because of page renames but is not writable.', -1);
            }
        }
    }

    /**
     * helper functions
     */
    function _auth_check_list($list) {
        global $conf;
        global $USERINFO;

        if(!$conf['useacl']) return true; //no ACL - then no checks

        $allowed = explode(',',$list);
        $allowed = array_map('trim', $allowed);
        $allowed = array_unique($allowed);
        $allowed = array_filter($allowed);

        if(!count($allowed)) return true; //no restrictions

        $user   = $_SERVER['REMOTE_USER'];
        $groups = (array) $USERINFO['grps'];

        if(in_array($user,$allowed)) return true; //user explicitly mentioned

        //check group memberships
        foreach($groups as $group){
            if(in_array('@'.$group,$allowed)) return true;
        }

        //still here? no access!
        return false;
    }
    
    function _auth_check_all($id) {
        return $this->_auth_can_rename($id) ||
            $this->_auth_can_delete($id);
    }
    
    function _auth_can_rename($id) {
        static $cache = null;
        if (!$cache[$id]) {
            $cache[$id] = auth_quickaclcheck($id)>=AUTH_EDIT &&
                $this->_auth_check_list($this->getConf('user_rename'));
        }
        return $cache[$id];
    }

    function _auth_can_rename_nr($id) {
        static $cache = null;
        if (!$cache[$id]) {
            $cache[$id] = auth_quickaclcheck($id)>=AUTH_DELETE &&
                $this->_auth_check_list($this->getConf('user_rename_nr'));
        }
        return $cache[$id];
    }

    function _auth_can_delete($id) {
        static $cache = null;
        if (!$cache[$id]) {
            $cache[$id] = auth_quickaclcheck($id)>=AUTH_DELETE &&
                $this->_auth_check_list($this->getConf('user_delete'));
        }
        return $cache[$id];
    }

    function _locate_filepairs(&$opts, $dir, $regex ){
        global $conf;
        $oldpath = $conf[$dir].'/'.str_replace(':','/',$opts['oldns']);
        $newpath = $conf[$dir].'/'.str_replace(':','/',$opts['newns']);
        if (!$opts['oldfiles']) $opts['oldfiles'] = array();
        if (!$opts['newfiles']) $opts['newfiles'] = array();
        $dh = @opendir($oldpath);
        if($dh) {
            while(($file = readdir($dh)) !== false){
                if ($file{0}=='.') continue;
                $oldfile = $oldpath.$file;
                if (is_file($oldfile) && preg_match($regex,$file)){
                    $opts['oldfiles'][] = $oldfile;
                    if ($opts['move']) {
                        $newfilebase = str_replace($opts['oldname'], $opts['newname'], $file);
                        $newfile = $newpath.$newfilebase;
                        if (@file_exists($newfile)) {
                            $this->errors[] = sprintf( $this->getLang('rp_msg_file_conflict'), $newfilebase );
                            return false;
                        }
                        $opts['newfiles'][] = $newfile;
                    }
                }
            }
            closedir($dh);
            return true;
        }
        return false;
    }

    function _apply_moves(&$opts) {
        foreach ($opts['oldfiles'] as $i => $oldfile) {
            $newfile = $opts['newfiles'][$i];
            $newdir = dirname($newfile);
            if (!io_mkdir_p($newdir)) continue;
            io_rename($oldfile, $newfile);
        }
    }

    function _apply_deletes(&$opts) {
        foreach ($opts['oldfiles'] as $oldfile) {
            unlink($oldfile);
        }
    }

    function _FN($id) {
        $id = str_replace(':','/',$id);
        $id = utf8_encodeFN($id);
        return $id;
    }

    function _custom_delete_page($id, $summary) {
        global $ID, $INFO, $conf;
        // mark as nonexist to prevent indexerWebBug
        if ($id==$ID) $INFO['exists'] = 0;
        // delete page, meta and attic
        $file = wikiFN($id);
        $old = @filemtime($file); // from page
        if (file_exists($file)) unlink($file);
        $opts['oldname'] = $this->_FN(noNS($id));
        $opts['oldns'] = $this->_FN(getNS($id));
        if ($opts['oldns']) $opts['oldns'] .= '/';
        $this->_locate_filepairs( $opts, 'metadir', '/^'.$opts['oldname'].'\.(?!mlist)\w*?$/' );
        $this->_locate_filepairs( $opts, 'olddir', '/^'.$opts['oldname'].'\.\d{10}\.txt(\.gz|\.bz2)?$/' );
        $this->_apply_deletes($opts);
        io_sweepNS($id, 'datadir');
        io_sweepNS($id, 'metadir');
        io_sweepNS($id, 'olddir');
        // send notify mails
        notify($id,'admin',$old,$summary);
        notify($id,'subscribers',$old,$summary);
        // update the purgefile (timestamp of the last time anything within the wiki was changed)
        io_saveFile($conf['cachedir'].'/purgefile',time());
        // if useheading is enabled, purge the cache of all linking pages
        if(useHeading('content')){
            $pages = ft_backlinks($id);
            foreach ($pages as $page) {
                $cache = new cache_renderer($page, wikiFN($page), 'xhtml');
                $cache->removeCache();
            }
        }
    }

    /**
     * @param string $text   The wiki text that shall be rewritten
     * @param string $id     The id of the wiki page, if the page itself was moved the old id
     * @param array  $moves  Array of all moves, the keys are the old ids, the values the new ids
     * @return string        The rewritten wiki text
     */
    function _rewrite_content($text, $id, $moves) {
        // resolve moves of pages that were moved more than once
        $tmp_moves = array();
        foreach ($moves as $old => $new) {
            if ($old != $id && isset($moves[$new]) && $moves[$new] != $new) {
                // write to temp array in order to correctly handle rename circles
                $tmp_moves[$old] = $moves[$new];
            }
        }

        $changed = !empty($tmp_moves);

        // this correctly resolves rename circles by moving forward one step a time
        while ($changed) {
            $changed = false;
            foreach ($tmp_moves as $old => $new) {
                if (isset($moves[$new]) && $moves[$new] != $new) {
                    $tmp_moves[$old] = $moves[$new];
                    $changed = true;
                }
            }
        }

        // manual merge, we can't use array_merge here as ids can be numeric
        foreach ($tmp_moves as $old => $new) {
            $moves[$old] = $new;
        }

        $modes = p_get_parsermodes();

        // Create the parser
        $Parser = new Doku_Parser();

        // Add the Handler
        $Parser->Handler = new action_plugin_editx_handler($id, $moves);

        //add modes to parser
        foreach($modes as $mode){
            $Parser->addMode($mode['mode'],$mode['obj']);
        }

        return $Parser->parse($text);
    }

    /**
     * main functions
     */
    function _rename_page(&$opts) {
        // check confirmation
        if (!$opts['confirm']) {
            $this->errors[] = $this->getLang('rp_msg_unconfirmed');
        }
        // check old page
        if (!$opts['oldpage']) {
            $this->errors[] = $this->getLang('rp_msg_old_empty');
        } else if (!page_exists($opts['oldpage'])) {
            $this->errors[] = sprintf( $this->getLang('rp_msg_old_noexist'), $opts['oldpage'] );
        } else if (!$this->_auth_can_rename($opts['oldpage'])) {
            $this->errors[] = sprintf( $this->getLang('rp_msg_auth'), $opts['oldpage'] );
        } else if (checklock($opts['oldpage'])) {
            $this->errors[] = sprintf( $this->getLang('rp_msg_locked'), $opts['oldpage'] );
        }
        // check noredirect
        if ($opts['nr'] && !$this->_auth_can_rename_nr($opts['oldpage']))
            $this->errors[] = $this->getLang('rp_msg_auth_nr');
        // check new page
        if (!$opts['newpage']) {
            $this->errors[] = $this->getLang('rp_msg_new_empty');
        } else if (page_exists($opts['newpage'])) {
            $this->errors[] = sprintf( $this->getLang('rp_msg_new_exist'), $opts['newpage'] );
        } else if (!$this->_auth_can_rename($opts['newpage'])) {
            $this->errors[] = sprintf( $this->getLang('rp_msg_auth'), $opts['newpage'] );
        } else if (checklock($opts['newpage'])) {
            $this->errors[] = sprintf( $this->getLang('rp_msg_locked'), $opts['newpage'] );
        }
        // try to locate moves
        if (!$this->errors) {
            $opts['move'] = true;
            $opts['oldname'] = $this->_FN(noNS($opts['oldpage']));
            $opts['newname'] = $this->_FN(noNS($opts['newpage']));
            $opts['oldns'] = $this->_FN(getNS($opts['oldpage']));
            $opts['newns'] = $this->_FN(getNS($opts['newpage']));
            if ($opts['oldns']) $opts['oldns'] .= '/';
            if ($opts['newns']) $opts['newns'] .= '/';
            $this->_locate_filepairs( $opts, 'metadir', '/^'.$opts['oldname'].'\.(?!mlist|meta|indexed)\w*?$/' );
            $this->_locate_filepairs( $opts, 'olddir', '/^'.$opts['oldname'].'\.\d{10}\.txt(\.gz|\.bz2)?$/' );
        }
        // if no error do rename
        if (!$this->errors) {
            // TODO: add move event
            $page_meta  = p_get_metadata($opts['oldpage'], 'plugin_editx', METADATA_DONT_RENDER);
            if (!$page_meta) $page_meta = array();
            if (!isset($page_meta['old_ids'])) $page_meta['old_ids'] = array();
            $page_meta['old_ids'][$opts['oldpage']] = time();

            // ft_backlinks() is not used here, as it does a hidden page and acl check but we really need all pages
            $affected_pages = idx_get_indexer()->lookupKey('relation_references', array_keys($page_meta['old_ids']));
            $text = rawWiki($opts['oldpage']);
            // move meta and attic
            $this->_apply_moves($opts);
            // save to newpage
            $text = $this->_rewrite_content($text, $opts['oldpage'], array($opts['oldpage'] => $opts['newpage']));
            if ($opts['summary'])
                $summary = sprintf( $this->getLang('rp_newsummaryx'), $opts['oldpage'], $opts['newpage'], $opts['summary'] );
            else
                $summary = sprintf( $this->getLang('rp_newsummary'), $opts['oldpage'], $opts['newpage'] );
            saveWikiText($opts['newpage'],$text,$summary);
            // purge or recreate old page
            $summary = $opts['summary'] ?
                sprintf( $this->getLang('rp_oldsummaryx'), $opts['oldpage'], $opts['newpage'], $opts['summary'] ) :
                sprintf( $this->getLang('rp_oldsummary'), $opts['oldpage'], $opts['newpage'] );
            if ($opts['nr']) {
                $this->_custom_delete_page( $opts['oldpage'], $summary );
                // write change log afterwards, or it would be deleted
                addLogEntry( null, $opts['oldpage'], DOKU_CHANGE_TYPE_DELETE, $summary ); // also writes to global changes
                unlink(metaFN($opts['oldpage'],'.changes')); // purge page changes
            }
            else {
                $text = $this->getConf('redirecttext');
                if (!$text) $text = $this->getLang('redirecttext');
                $text = str_replace( '@ID@', $opts['newpage'], $text );
                @unlink(wikiFN($opts['oldpage']));  // remove old page file so no additional history
                saveWikiText($opts['oldpage'],$text,$summary);
            }

            foreach ($page_meta['old_ids'] as $page_id => $time) {
                if (!isset($affected_pages[$page_id])) continue;
                foreach ($affected_pages[$page_id] as $id) {
                    if (!page_exists($id, '', false) || $id == $page_id || $id == $opts['newpage']) continue;
                    // if the page has been modified since the rename of the old page, the link in the new page is most
                    // probably intentionally to the old page and shouldn't be changed
                    if (filemtime(wikiFN($id, '', false)) > $time) continue;
                    // we are only interested in persistent metadata, so no need to render anything.
                    $meta = p_get_metadata($id, 'plugin_editx', METADATA_DONT_RENDER);
                    if (!$meta) $meta = array('moves' => array());
                    if (!isset($meta['moves'])) $meta['moves'] = array();
                    $meta['moves'][$page_id] = $opts['newpage'];
                    // remove redundant moves (can happen when a page is moved back to its old id)
                    if ($page_id == $opts['newpage']) unset($meta['moves'][$page_id]);
                    if (empty($meta['moves'])) unset($meta['moves']);
                    p_set_metadata($id, array('plugin_editx' => $meta), false, true);
                }
            }

            p_set_metadata($opts['newpage'], array('plugin_editx' => $page_meta), false, true);
        }
        // show messages
        if ($this->errors) {
            foreach ($this->errors as $error) msg( $error, -1 );
        }
        else {
            $msg = sprintf( $this->getLang('rp_msg_success'), $opts['oldpage'], $opts['newpage'] );
            msg( $msg, 1 );
        }
        // display form and table
        $data = array( rp_newpage => $opts['newpage'], rp_summary => $opts['summary'], rp_nr => $opts['rp_nr'] );
        if (!defined('DOKU_UNITTEST')) $this->_print_form($data);
    }

    function _delete_page(&$opts) {
        // check confirm
        if (!$opts['confirm']) {
            $this->errors[] = $this->getLang('dp_msg_unconfirmed');
        }
        // check old page
        if (!$opts['oldpage']) {
            $this->errors[] = $this->getLang('dp_msg_old_empty');
        } else if (!$this->_auth_can_delete($opts['oldpage'])) {
            $this->errors[] = sprintf( $this->getLang('dp_msg_auth'), $opts['oldpage'] );
        }
        // if no error do delete
        if (!$this->errors) {
            $summary = $opts['summary'] ? 
                sprintf( $this->getLang('dp_oldsummaryx'), $opts['summary'] ) :
                $this->getLang('dp_oldsummary');
            $this->_custom_delete_page( $opts['oldpage'], $summary );
            // write change log afterwards, or it would be deleted
            addLogEntry( null, $opts['oldpage'], DOKU_CHANGE_TYPE_DELETE, $summary ); // also writes to global changes
            if ($opts['purge']) unlink(metaFN($opts['oldpage'],'.changes')); // purge page changes
        }
        // show messages
        if ($this->errors) {
            foreach ($this->errors as $error) msg( $error, -1 );
        }
        else {
            $msg = sprintf( $this->getLang('dp_msg_success'), $opts['oldpage'] );
            msg( $msg, 1 );
        }
        // display form and table
        $data = array( dp_purge => $opts['purge'], dp_summary => $opts['summary'] );
        $this->_print_form($data);
    }

    function _print_form($data=null) {
        global $ID, $lang;
        $chk = ' checked="checked"';
?>
<h1><?php echo sprintf( $this->getLang('title'), $ID); ?></h1>
<div id="config__manager">
<?php 
    if ($this->_auth_can_rename($ID)) {
?>
    <form action="<?php echo wl($ID); ?>" method="post">
    <fieldset>
    <legend><?php echo $this->getLang('rp_title'); ?></legend>
        <input type="hidden" name="do" value="editx" />
        <input type="hidden" name="work" value="rename" />
        <input type="hidden" name="oldpage" value="<?php echo $ID; ?>" />
        <table class="inline">
            <tr>
                <td class="label"><?php echo $this->getLang('rp_newpage'); ?></td>
                <td class="value"><input class="edit" type="input" name="newpage" value="<?php echo $data['rp_newpage']; ?>" /></td>
            </tr>
            <tr>
                <td class="label"><?php echo $this->getLang('rp_summary'); ?></td>
                <td class="value"><input class="edit" type="input" name="summary" value="<?php echo $data['rp_summary']; ?>" /></td>
            </tr>
<?php 
        if ($this->_auth_can_rename_nr($ID)) {
?>
            <tr>
                <td class="label"><?php echo $this->getLang('rp_nr'); ?></td>
                <td class="value"><input type="checkbox" name="rp_nr" value="1"<?php if ($data['rp_nr']) echo $chk; ?> /></td>
            </tr>
<?php
    }
?>
            <tr>
                <td class="label"><?php echo $this->getLang('rp_confirm'); ?></td>
                <td class="value"><input type="checkbox" name="rp_confirm" value="1" /></td>
            </tr>
        </table>
        <p>
            <input type="submit" class="button" value="<?php echo $lang['btn_save']; ?>" />
            <input type="reset" class="button" value="<?php echo $lang['btn_reset']; ?>" />
        </p>
    </fieldset>
    </form>
<?php
    }
    if ($this->_auth_can_delete($ID)) {
?>
    <form action="<?php echo wl($ID); ?>" method="post">
    <fieldset>
    <legend><?php echo $this->getLang('dp_title'); ?></legend>
        <input type="hidden" name="do" value="editx" />
        <input type="hidden" name="work" value="delete" />
        <input type="hidden" name="oldpage" value="<?php echo $ID; ?>" />
        <table class="inline">
            <tr>
                <td class="label"><?php echo $this->getLang('dp_summary'); ?></td>
                <td class="value"><input class="edit" type="input" name="summary" value="<?php echo $data['dp_summary']; ?>" /></td>
            </tr>
            <tr>
                <td class="label"><?php echo $this->getLang('dp_purge'); ?></td>
                <td class="value"><input type="checkbox" name="dp_purge" value="1"<?php if ($data['dp_purge']) echo $chk; ?> /></td>
            </tr>
            <tr>
                <td class="label"><?php echo $this->getLang('dp_confirm'); ?></td>
                <td class="value"><input type="checkbox" name="dp_confirm" value="1" /></td>
            </tr>
        </table>
        <p>
            <input type="submit" class="button" value="<?php echo $lang['btn_save']; ?>" />
            <input type="reset" class="button" value="<?php echo $lang['btn_reset']; ?>" />
        </p>
    </fieldset>
    </form>
<?php
    }
?>
</div>
<?php
    }
}

class action_plugin_editx_handler {
    public $calls = '';
    private $id;
    private $ns;
    private $new_id;
    private $new_ns;
    private $moves;

    public function __construct($id, $moves) {
        $this->id = $id;
        $this->ns = getNS($id);
        $this->moves = $moves;
        if (isset($moves[$id])) {
            $this->new_id = $moves[$id];
            $this->new_ns = getNS($moves[$id]);
        } else {
            $this->new_id = $id;
            $this->new_ns = $this->ns;
        }
    }

    public function camelcaselink($match, $state, $pos) {
        if ($this->ns)
            $old = cleanID($this->ns.':'.$match);
        else
            $old = cleanID($match);
        if (isset($this->moves[$old]) || $this->id != $this->new_id) {
            if (isset($this->moves[$old])) {
                $new = $this->moves[$old];
            } else {
                $new = $old;
            }
            $new_ns = getNS($new);
            // preserve capitalization either in the link or in the title
            if (noNS($new) == noNS($old)) {
                // camelcase link still seems to work
                if ($new_ns == $this->new_ns) {
                    $this->calls .= $match;
                } else { // just the namespace was changed, the camelcase word is a valid id
                    $this->calls .= "[[$new_ns:$match]]";
                }
            } else {
                $this->calls .= "[[$new|$match]]";
            }
        } else {
            $this->calls .= $match;
        }
        return true;
    }

    public function internallink($match, $state, $pos) {
        global $conf;
        // Strip the opening and closing markup
        $link = preg_replace(array('/^\[\[/','/\]\]$/u'),'',$match);

        // Split title from URL
        $link = explode('|',$link,2);
        if ( !isset($link[1]) ) {
            $link[1] = NULL;
        }
        $link[0] = trim($link[0]);


        //decide which kind of link it is

        if ( preg_match('/^[a-zA-Z0-9\.]+>{1}.*$/u',$link[0]) ) {
            // Interwiki
            $this->calls .= $match;
        }elseif ( preg_match('/^\\\\\\\\[^\\\\]+?\\\\/u',$link[0]) ) {
            // Windows Share
            $this->calls .= $match;
        }elseif ( preg_match('#^([a-z0-9\-\.+]+?)://#i',$link[0]) ) {
            // external link (accepts all protocols)
            $this->calls .= $match;
        }elseif ( preg_match('<'.PREG_PATTERN_VALID_EMAIL.'>',$link[0]) ) {
            // E-Mail (pattern above is defined in inc/mail.php)
            $this->calls .= $match;
        }elseif ( preg_match('!^#.+!',$link[0]) ){
            // local link
            $this->calls .= $match;
        }else{
            $id = $link[0];

            $hash = '';
            $parts = explode('#', $id, 2);
            if (count($parts) === 2) {
                $id = $parts[0];
                $hash = $parts[1];
            }

            $params = '';
            $parts = explode('?', $id, 2);
            if (count($parts) === 2) {
                $id = $parts[0];
                $params = $parts[1];
            }

            if ($id === '') {
                $this->calls .= $match;
                return true;
            }

            $abs_id = resolve_id($this->ns, $id, false);
            $clean_id = cleanID($abs_id);
            // FIXME this simply assumes that the link pointed to :$conf['start'], but it could also point to another page
            // resolve_pageid does a lot more here, but we can't really assume this as the original pages might have been
            // deleted already
            if (substr($clean_id, -1) === ':')
                $clean_id .= $conf['start'];

            if (isset($this->moves[$clean_id]) || $this->id !== $this->new_id) {
                if (isset($this->moves[$clean_id])) {
                    $new = $this->moves[$clean_id];
                } else {
                    $new = $clean_id;
                }
                $new_link = $new;
                $new_ns = getNS($new);
                // try to keep original pagename
                if (noNS($new) == noNS($clean_id)) {
                    if ($new_ns == $this->new_ns) {
                        $new_link = noNS($id);
                        if ($id == ':')
                            $new_link = ':';
                    } else if ($new_ns != false) {
                        $new_link = $new_ns.':'.noNS($id);
                    } else {
                        $new_link = noNS($id);
                    }
                }
                // TODO: change subnamespaces to relative links

                //msg("Changing $match, clean id is $clean_id, new id is $new, new namespace is $new_ns, new link is $new_link");

                if ($this->new_ns != '' && $new_ns == false) {
                    $new_link = ':'.$new_link;
                }

                if ($params !== '') {
                    $new_link .= '?'.$params;
                }

                if ($hash !== '') {
                    $new_link .= '#'.$hash;
                }

                if ($link[1] != NULL) {
                    $new_link .= '|'.$link[1];
                }

                $this->calls .= '[['.$new_link.']]';
            } else {
                $this->calls .= $match;
            }

        }

        return true;

    }
    public function plugin($match, $state, $pos, $pluginname) {
        $this->calls .= $match;
        // FIXME: handle plugins
        return true;
    }
    public function __call($name, $params) {
        if (count($params) == 3) {
            $this->calls .= $params[0];
            return true;
        } else {
            trigger_error('Error, handler function '.hsc($name).' with '.count($params).' parameters called which isn\'t implemented', E_USER_ERROR);
        }
    }

    public function _finalize() {
        // remove padding that is added by the parser in parse()
        $this->calls = substr($this->calls, 1, -1);
    }
}
// vim:ts=4:sw=4:et:enc=utf-8:
