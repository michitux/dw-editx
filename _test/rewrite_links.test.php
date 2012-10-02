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
}
