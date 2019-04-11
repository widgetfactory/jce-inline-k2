<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  System.JCE_Inline_Com_K2
 *
 * @copyright   Copyright (C) 2017 Ryan Demmer. All rights reserved.
 * @license     GNU General Public License version 3 or later
 */
defined('_JEXEC') or die;

/**
 * Medium Inline Editing Plugin
 *
 * @package     Joomla.Plugin
 * @subpackage  System.JCE_Inline_Com_K2
 */
class PlgSystemJce_Inline_Com_K2 extends JPlugin
{
    private function checkUser($id)
    {
        include_once JPATH_SITE . '/components/com_k2/helpers/permissions.php';
        
        $db = JFactory::getDBO();

        $query = $db->getQuery(true);
        $query->select('*')->from('#__k2_items')->where('id = ' . (int) $id);
        $db->setQuery($query);
        $row = $db->loadObject();

        // invalid table row
        if (empty($row)) {
            return false;
        }

        K2HelperPermissions::setPermissions();

        return K2HelperPermissions::canEditItem($row->created_by, $row->catid);
    }

    /**
     * Prepare content for inline editing
     *
     * @param   string   $context  The context of the content being passed to the plugin.
     * @param   mixed    &$row     Content object.
     * @param   mixed    &$params  Additional parameters.
     * @param   integer  $page     Optional page number. Unused. Defaults to zero.
     *
     * @return  boolean    True on success.
     */
    public function onContentPrepare($context, &$item, &$params, $page = 0)
    {
        // Don't run this plugin when the content is being indexed
        if ($context == 'com_finder.indexer' || empty($item->id)) {
            return true;
        }

        if ($context !== "com_k2.item" && $context !== "com_k2.category") {
            return false;
        }

        $app = JFactory::getApplication();

        if ($app->isAdmin()) {
            return false;
        }

        if (JFactory::getConfig()->get('editor') !== 'jce') {
            return false;
        }

        // don't process on save return
        if ($app->input->get('option') === "com_jce") {
            return false;
        }

        if ($this->checkUser($item->id)) {

            $title  = $item->title;
            $text   = $item->introtext;

            if (!empty($item->fulltext) && $context === "com_k2.item") {
                $text .= '<hr id="system-readmore" />' . $item->fulltext;
            }

            $item->text = '<div class="wf-inline-editable-content wf-editor" title="Click to edit">' . $text . '</div>';
            $item->text .= '<div class="wf-inline-editable-content-params">';
            $item->text .= '    <input type="hidden" data-token="1" name="' . JSession::getFormToken() . '" value="1" />';
            $item->text .= '    <input type="hidden" name="inline_context" value="' . $context . '" />';
            $item->text .= '    <input type="hidden" name="inline_content_id" value="' . $item->id . '" />';
            $item->text .= '    <input type="hidden" name="inline_content_language" value="' . $item->language . '" />';
            $item->text .= '    <input type="hidden" name="inline_content_catid" value="' . $item->catid . '" />';
            $item->text .= '</div>';

            $app->set('wf-inline-edit', true);
        }
    }

    public function onWfInlineEditGetContent(&$data, $context)
    {
        if ($context !== "com_k2.item" && $context !== "com_k2.category") {
            return false;
        }

        if (JFactory::getConfig()->get('editor') !== 'jce') {
            return false;
        }

        $app = JFactory::getApplication();

        $id = $app->input->getInt('inline_content_id');

        if (empty($id)) {
            return false;
        }

        JTable::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_k2/tables');

        $row = JTable::getInstance('K2Item', 'Table');

        // invalid table row
        if (!$row->load($id)) {
            return false;
        }

        if (!$this->checkUser($id)) {
            return false;
        }

        $data = $row->introtext;

        if (!empty($row->fulltext) && $context === "com_content.article") {
            $data .= '<hr id="system-readmore" />' . $row->fulltext;
        }
    }

    public function onWfInlineEditSaveContent(&$data, $context)
    {
        if ($context !== "com_k2.item" && $context !== "com_k2.category") {
            return false;
        }

        $app = JFactory::getApplication();
        $user = JFactory::getUser();

        // set return object
        $return = (object) array('text' => '');

        $id = $app->input->post->getInt('inline_content_id');

        if (empty($id)) {
            return false;
        }

        $catid = $app->input->post->getInt('inline_content_catid');
        $language = $app->input->post->getWord('inline_content_language');

        JTable::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_k2/tables');

        $row = JTable::getInstance('K2Item', 'Table');

        // invalid table row
        if (!$row->load($id)) {            
            return false;
        }

        // check user can edit
        if (!$this->checkUser($id)) {
            return false;
        }

        $dispatcher = JEventDispatcher::getInstance();
        $params = JComponentHelper::getParams('com_k2');

        $text = $app->input->post->get('inline_text', '', 'RAW');

        // filter text to save
        $text = JComponentHelper::filterText($text);

        if ($params->get('xssFiltering')) {
            $filter = new JFilterInput(array(), array(), 1, 1, 0);
            $text = $filter->clean($text);
        }

        $pattern = '#<hr\s+id=("|\')system-readmore("|\')\s*\/*>#i';
        $tagPos = preg_match($pattern, $text);

        // split text at readmore
        if ($tagPos === 0) {
            $row->introtext = $text;
        } else {
            list($row->introtext, $fulltext) = preg_split($pattern, $text, 2);
        }

        $datenow = JFactory::getDate();
        $row->modified = $datenow->toSql();
        $row->modified_by = $user->get('id');

        JPluginHelper::importPlugin('k2');
        $result = $dispatcher->trigger('onBeforeK2Save', array(
            &$row,
            false,
        ));

        JPluginHelper::importPlugin('finder');
        $results = $dispatcher->trigger('onFinderBeforeSave', array(
            'com_k2.item',
            $row,
            false,
        ));

        if (!$row->check()) {
            return $return;
        }

        if (!$row->store()) {
            return $return;
        }

        $dispatcher->trigger('onAfterK2Save', array(
            &$row,
            false,
        ));

        $dispatcher->trigger('onAfterContentSave', array(
            &$row,
            false,
        ));

        // set for content plugins
        $row->text = $row->introtext;

        // process text
        JPluginHelper::importPlugin('content');
        $dispatcher->trigger('onContentPrepare', array($context, &$row, &$row->params, 0));

        $row->introtext = $row->text;

        if (!empty($fulltext) && $context === "com_k2.item") {
            $row->introtext .= '<hr id="system-readmore" />' . $fulltext;
        }

        // update text
        $data = $row->introtext;
    }
}
