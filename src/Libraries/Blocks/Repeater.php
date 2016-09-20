<?php namespace CoasterCms\Libraries\Blocks;

use Carbon\Carbon;
use CoasterCms\Helpers\Cms\Email;
use CoasterCms\Helpers\Cms\View\CmsBlockInput;
use CoasterCms\Helpers\Cms\View\FormWrap;
use CoasterCms\Helpers\Cms\View\PaginatorRender;
use CoasterCms\Libraries\Builder\FormMessage;
use CoasterCms\Libraries\Builder\PageBuilder;
use CoasterCms\Models\Block;
use CoasterCms\Models\BlockFormRule;
use CoasterCms\Models\BlockRepeater;
use CoasterCms\Models\PageBlockRepeaterData;
use Illuminate\Pagination\LengthAwarePaginator;
use Request;
use Validator;
use View;

class Repeater extends String_
{
    private static $_preloaded_repeater_data;
    private static $_current_repeater = null;

    public function display($repeaterId, $options = [])
    {
        $template = !empty($options['view']) ? $options['view'] : $this->_block->name;
        $repeatersViews = 'themes.' . PageBuilder::getData('theme') . '.blocks.repeaters.';

        if (!empty($options['form'])) {
            return FormWrap::view($this->_block, $options, $repeatersViews . $template . '-form');
        }

        if (View::exists($repeatersViews . $template)) {
            $renderedContent = '';
            if ($rep_blocks = BlockRepeater::preload($this->_block->id)) {
                $rep_blocks = explode(',', $rep_blocks->blocks);

                $random = !empty($options['random']) ? $options['random'] : false;
                $repeaterRows = PageBlockRepeaterData::load_by_repeater_id($repeaterId, $options['version'], $random);

                // pagination
                if (!empty($options['per_page']) && !empty($repeaterRows)) {
                    $block_rows_paginator = new LengthAwarePaginator($repeaterRows, count($repeaterRows), $options['per_page'], Request::input('page', 1));
                    $block_rows_paginator->setPath(Request::getPathInfo());
                    $links = PaginatorRender::run($block_rows_paginator);
                    $repeaterRows = array_slice($repeaterRows, (($block_rows_paginator->currentPage() - 1) * $options['per_page']), $options['per_page']);
                } else {
                    $links = '';
                }

                if (!empty($repeaterRows)) {
                    $i = 1;
                    $is_first = true;
                    $is_last = false;
                    $rows = count($repeaterRows);
                    $cols = !empty($options['cols']) ? (int)$options['cols'] : 1;
                    $column = !empty($options['column']) ? (int)$options['column'] : 1;
                    $previous = self::$_current_repeater;
                    self::$_current_repeater = $repeaterId;
                    self::$_preloaded_repeater_data[$repeaterId] = array();
                    foreach ($repeaterRows as $row) {
                        if ($i % $cols == $column % $cols) {
                            foreach ($rep_blocks as $rep_block) {
                                // save block data for when view is being processed
                                $block_info = Block::preload($rep_block);
                                if ($block_info->exists) {
                                    if (!empty($row[$rep_block])) {
                                        self::$_preloaded_repeater_data[$repeaterId][$block_info->name] = $row[$rep_block];
                                    } else {
                                        self::$_preloaded_repeater_data[$repeaterId][$block_info->name] = '';
                                    }
                                }
                            }
                            if ($i + $cols - 1 >= $rows)
                                $is_last = true;
                            $renderedContent .= View::make($repeatersViews . $template, array('is_first' => $is_first, 'is_last' => $is_last, 'count' => $i, 'total' => $rows, 'id' => $block_data, 'pagination' => $links, 'links' => $links))->render();
                            $is_first = false;
                        }
                        $i++;
                    }
                    self::$_current_repeater = $previous;
                }
            }
            return $renderedContent;
        } else {
            return "Repeater view does not exist in theme";
        }
    }

    public function submission($formData)
    {
        $formRules = BlockFormRule::get_rules($this->_block->name.'-form');
        $v = Validator::make($formData, $formRules);
        if ($v->passes()) {

            foreach ($formData as $blockName => $content) {
                $fieldBlock = Block::preload($blockName);
                if ($fieldBlock->exists) {
                    if ($fieldBlock->type == 'datetime' && empty($content)) {
                        $content = new Carbon();
                    }
                    $formData[$blockName] = $content;
                }
            }

            $this->insertRow($formData);

            Email::sendFromFormData([$this->_block->name.'-form'], $formData, config('coaster::site.name') . ': New Form Submission - ' . $this->_block->label);

            return \redirect(Request::url());

        } else {
            FormMessage::set($v->messages());
        }

        return false;
    }

    public function edit($content, $newRow = false)
    {
        // if no current repeater id, reserve next new repeater id for use on save
        $repeaterId = $content ?: PageBlockRepeaterData::next_free_repeater_id();
        $this->_editViewData['renderedRows'] = '';

        if ($repeaterBlock = BlockRepeater::where('block_id', '=', $this->_block->id)->first()) {
            // load repeater blocks
            $repeaterBlocks = [];
            foreach (Block::whereIn('id', explode(",", $repeaterBlock->blocks))->orderBy('order', 'asc')->get() as $repeaterBlock) {
                $repeaterBlocks[$repeaterBlock->id] = $repeaterBlock;
            }

            // check if new or existing row needs displaying
            if ($newRow) {
                $renderedRow = '';
                $repeaterRowId = PageBlockRepeaterData::next_free_row_id($repeaterId);
                foreach ($repeaterBlocks as $repeaterBlock) {
                    $renderedRow .= $repeaterBlock->setPageId($this->_block->getPageId())->setRepeaterData($repeaterId, $repeaterRowId)->getTypeObject()->edit('');
                }
                return CmsBlockInput::make('repeater.row', array('repeater_id' => $repeaterId, 'row_id' => $repeaterRowId, 'blocks' => $renderedRow));
            } else {
                $repeaterRowsData = PageBlockRepeaterData::load_by_repeater_id($repeaterId, $this->_block->getVersionId());
                foreach ($repeaterRowsData as $repeaterRowId => $repeaterRowData) {
                    $renderedRow = '';
                    foreach ($repeaterBlocks as $repeaterBlockId => $repeaterBlock) {
                        $fieldContent = isset($repeaterRowData[$repeaterBlockId]) ? $repeaterRowData[$repeaterBlockId] : '';
                        $renderedRow .= $repeaterBlock->setPageId($this->_block->getPageId())->setRepeaterData($repeaterId, $repeaterRowId)->getTypeObject()->edit($fieldContent);
                    }
                    $this->_editViewData['renderedRows'] .= CmsBlockInput::make('repeater.row', array('repeater_id' => $repeaterId, 'row_id' => $repeaterRowId, 'blocks' => $renderedRow));
                }
            }
        }

        $this->_editViewData['_repeaterId'] = $this->_block->getRepeaterId();
        $this->_editViewData['_repeaterRowId'] = $this->_block->getRepeaterRowId();

        return parent::edit($content);
    }

    public function save($content)
    {
        $return = parent::save($content['repeater_id']);

        // load current and submitted data
        $existingRepeaterRows = PageBlockRepeaterData::loadRepeaterData($content['repeater_id'], $this->_block->getVersionId());
        $submittedRepeaterRows = Request::input('repeater.' . $content['repeater_id']) ?: [];

        // if row missing, overwrite all data with blanks in new version
        if ($existingRepeaterRows) {
            foreach ($existingRepeaterRows as $rowId => $existingRepeaterRow) {
                if (empty($submittedRepeaterRows[$rowId])) {
                    foreach ($existingRepeaterRow as $existingRepeaterBlock) {
                        $block = Block::preload($existingRepeaterBlock->block_id);
                        if ($block->exists) {
                            $block->setVersionId($this->_block->getVersionId())->setRepeaterData($content['repeater_id'], $rowId)->setPageId($this->_block->getPageId())->getTypeObject()->save('');
                        }
                    }
                }
            }
        }

        // save new data
        $this->_contentSaved = '';
        $rowOrderNumber = 0;
        foreach ($submittedRepeaterRows as $rowId => $submittedRepeaterRow) {
            $rowOrderNumber++;
            foreach ($submittedRepeaterRow as $submittedBlockId => $submittedBlockData) {
                $block = Block::preload($submittedBlockId);
                if (!$submittedBlockId) {
                    $block->id = 0;
                    $submittedBlockData = $rowOrderNumber;
                }
                if ($block->exists || $block->id == 0) {
                    $blockTypeObject = $block->setVersionId($this->_block->getVersionId())->setRepeaterData($content['repeater_id'], $rowId)->setPageId($this->_block->getPageId())->getTypeObject()->save($submittedBlockData);
                    if ($block->exists) {
                        if (($savedContent = $blockTypeObject->generateSearchText($blockTypeObject->getSavedContent())) !== null) {
                            $this->_contentSaved .= $savedContent . "\n";
                        }
                    }
                }
            }
        }

        return $return;
    }

    // repeater specific functions below

    /**
     * @param array $repeaterBlockContents
     */
    public function insertRow($repeaterBlockContents)
    {
        if (!($repeaterId = $this->_block->getRepeaterId())) {
            $repeaterId = PageBlockRepeaterData::next_free_repeater_id();
            $this->save(['repeater_id' => $repeaterId]);
            $currentRepeaterRows = [];
        } else {
            $currentRepeaterRows = PageBlockRepeaterData::load_by_repeater_id($repeaterId);
        }
        $repeaterRowId = PageBlockRepeaterData::next_free_row_id($repeaterId);

        if (!array_key_exists(0, $repeaterBlockContents)) {
            if (!empty($currentRepeaterRows)) {
                $rowOrders = array_map(function ($row) {return !empty($row[0]) ? $row[0] : 0;}, $currentRepeaterRows);
                $repeaterBlockContents[0] = max($rowOrders) + 1;
            } else {
                $repeaterBlockContents[0] = 1;
            }
        }

        foreach ($repeaterBlockContents as $blockName => $content) {
            $block = Block::preload($blockName);
            if ($block->exists || $blockName == 0) {
                $block->setVersionId($this->_block->getVersionId())->setRepeaterData($repeaterId, $repeaterRowId)->setPageId($this->_block->getPageId())->getTypeObject()->save($content);
            }
        }
    }

    public static function new_row()
    {
        $block = Block::find(Request::input('block_id'));
        $repeaterId = Request::input('repeater_id');
        if  ($repeaterId && $block && $block->type == 'repeater') {
            return $block->setPageId(Request::input('page_id'))->getTypeObject()->edit($repeaterId, true);
        }
        return 0;
    }

    public static function load_repeater_data($block_name)
    {
        if (isset(self::$_preloaded_repeater_data[self::$_current_repeater][$block_name])) {
            return self::$_preloaded_repeater_data[self::$_current_repeater][$block_name];
        }
        return false;
    }


}
