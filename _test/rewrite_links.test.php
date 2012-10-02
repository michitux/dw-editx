<?php
/**
 * Tests the rewriting of links when pages are moved
 */
class plugin_editx_rewrite_links_test extends DokuWikiTest {

    public function setup() {
        $this->pluginsEnabled[] = 'editx';
        parent::setup();
    }

    public function test_relative_links() {
        saveWikiText('editx', '[[start]] %%[[start]]%% [[wiki:syntax]] [[]] [[:]] [[test:start]]', 'Testcase created');
        /** @var $editx action_plugin_editx */
        $editx = plugin_load('action', 'editx');
        $opts['confirm'] = true;
        $opts['oldpage'] = 'editx';
        $opts['newpage'] = 'test:editx';
        $editx->_rename_page($opts);
        $this->assertEquals('[[:start]] %%[[start]]%% [[wiki:syntax]] [[]] [[:]] [[start]]',rawWiki('test:editx'));
    }

    public function test_camelcase() {
        global $conf;
        $conf['camelcase'] = 1;
        saveWikiText('editx', 'This is WikiTest. EdiTx', 'Testcase create');

        /** @var $editx action_plugin_editx */
        $editx = plugin_load('action', 'editx');
        $opts['confirm'] = true;
        $opts['oldpage'] = 'editx';
        $opts['newpage'] = 'test:foobar';
        $editx->_rename_page($opts);
        $this->assertEquals('This is [[:WikiTest]]. [[test:foobar|EdiTx]]', rawWiki('test:foobar'));
    }

    public function test_simple_rename() {
        saveWikiText('editx', 'Page to rename', 'Testcase create');
        saveWikiText('links', '[[links]] [[editx]] [[:eDitX]] [[editx#test]] [[editx?do=edit]]', 'Testcase created');
        $references = array_keys(p_get_metadata('links', 'relation references', METADATA_RENDER_UNLIMITED));
        idx_get_indexer()->addMetaKeys('links', 'relation_references', $references);

        /** @var $editx action_plugin_editx */
        $editx = plugin_load('action', 'editx');
        $opts['confirm'] = true;
        $opts['oldpage'] = 'editx';
        $opts['newpage'] = 'test:edit';
        $editx->_rename_page($opts);
        $this->assertEquals('[[links]] [[test:edit]] [[test:edit]] [[test:edit#test]] [[test:edit?do=edit]]', rawWiki('links'));
    }

    public function test_double_rename() {
        saveWikiText('editx', 'Page to rename', 'Testcase create');
        saveWikiText('links', '[[links]] [[editx]] [[:eDitX]] [[editx#test]] [[editx?do=edit]]', 'Testcase created');
        $references = array_keys(p_get_metadata('links', 'relation references', METADATA_RENDER_UNLIMITED));
        idx_get_indexer()->addMetaKeys('links', 'relation_references', $references);

        /** @var $editx action_plugin_editx */
        $editx = plugin_load('action', 'editx');
        $opts['confirm'] = true;
        $opts['oldpage'] = 'editx';
        $opts['newpage'] = 'test1:edit';
        $editx->_rename_page($opts);
        $opts['oldpage'] = 'test1:edit';
        $opts['newpage'] = 'test2:editx';
        $editx->_rename_page($opts);
        $this->assertEquals('[[links]] [[test2:editx]] [[test2:eDitX]] [[test2:editx#test]] [[test2:editx?do=edit]]', rawWiki('links'));
    }
}
