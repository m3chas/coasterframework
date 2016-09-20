<?php namespace CoasterCms\Models;

use CoasterCms\Libraries\Traits\DataPreLoad;
use DB;
use Eloquent;
use Request;

class Block extends Eloquent
{
    use DataPreLoad;

    /**
     * @var string
     */
    protected $table = 'blocks';

    /**
     * @var int
     */
    protected $_pageId = 0;

    /**
     * @var int
     */
    protected $_repeaterId = 0;

    /**
     * @var int
     */
    protected $_repeaterRowId = 0;

    /**
     * @var int
     */
    protected $_versionId = 0;

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function languages()
    {
        return $this->hasMany('CoasterCms\Models\PageBlockDefault');
    }

    /**
     * preload by both id and name
     * @return array
     */
    protected static function _preloadByColumn()
    {
        return ['id', 'name'];
    }

    /**
     * @param string $blockType
     * @return array
     */
    public static function getBlockIdsOfType($blockType)
    {
        $key = 'type'. ucwords($blockType) .'Ids';
        if (!static::_preloadIsset($key)) {
            $data = static::where('type', '=', $blockType)->get();
            static::_preloadOnce($data, $key, ['id'], 'id');
        }
        return static::_preloadGetArray($key);
    }

    public static function get_block_on_page($block_id, $page_id)
    {
        if ($page_id) {
            $page = Page::find($page_id);
            if (!empty($page)) {
                $block_cats = Template::template_blocks(config('coaster::frontend.theme'), $page->template);
            }
        } else {
            $block_cats = Theme::theme_blocks(config('coaster::frontend.theme'));
        }
        if (!empty($block_cats)) {
            foreach ($block_cats as $block_cat) {
                foreach ($block_cat as $block) {
                    if ($block->id == $block_id) {
                        return Block::find($block_id);
                    }
                }
            }
        }
        return null;
    }

    /**
     * @return array
     */
    public static function nameToNameArray()
    {
        static::_preloadOnce(null, 'nameToName', ['name'], 'name');
        return static::_preloadGetArray('nameToName');
    }

    /**
     * @return array
     */
    public static function idToLabelArray()
    {
        static::_preloadOnce(null, 'idToLabel', ['id'], 'label');
        return static::_preloadGetArray('idToLabel');
    }

    /**
     * @return \CoasterCms\Libraries\Blocks\String_
     */
    public function getTypeObject()
    {
        $typeClass = $this->getClass();
        return new $typeClass($this);
    }

    /**
     * @param string $blockClass
     * @param bool $reload
     * @return string
     */
    public static function getBlockType($blockClass, $reload = false)
    {
        static::_loadClasses($reload);
        return array_search($blockClass, static::_preloadGetArray('blockClass')) ?: 'string';
    }

    /**
     * @return string
     */
    public function getClass()
    {
        return static::getBlockClass($this->type);
    }

    /**
     * @param string $type
     * @param bool $reload
     * @return string
     */
    public static function getBlockClass($type, $reload = false)
    {
        static::_loadClasses($reload);
        return static::_preloadGet('blockClass', $type) ?: static::_preloadGet('blockClass', 'string');
    }

    /**
     * @param bool $reload
     * @return array
     */
    public static function getBlockClasses($reload = false)
    {
        static::_loadClasses($reload);
        return static::_preloadGetArray('blockClass');
    }

    /**
     * @param bool $reload
     */
    protected static function _loadClasses($reload = false)
    {
        if (!static::_preloadIsset('blockClass') || $reload) {
            $paths = [
                'CoasterCms\\Libraries\\Blocks\\' => base_path('vendor/web-feet/coasterframework/src/Libraries/Blocks'),
                'App\\Blocks\\' => base_path('app/Blocks')
            ];

            foreach ($paths as $classPath => $dirPath) {
                if (is_dir($dirPath)) {
                    foreach (scandir($dirPath) as $file) {
                        if ($className = explode('.', $file)[0]) {
                            static::_preloadAdd('blockClass', trim(strtolower($className), '_'), $classPath . $className);
                        }
                    }
                }
            }
        }
    }

    /**
     * @param int $pageId
     * @param int $versionId
     * @param bool $publish
     */
    public static function submit($pageId, $versionId, $publish = true)
    {
        $submittedBlocks = Request::input('block') ?: [];
        foreach ($submittedBlocks as $blockId => $submittedData) {
            $block = static::preload($blockId);
            if ($block->exists) {
                $blockTypeObject = $block->setPageId($pageId)->setVersionId($versionId)->getTypeObject()->save($submittedData);
                if ($publish) {
                    $blockTypeObject->publish();
                }
            }
        }
    }

    /**
     * @param int $versionId
     * @return $this
     */
    public function setVersionId($versionId)
    {
        $this->_versionId = $versionId;
        return $this;
    }

    /**
     * @param int $pageId
     * @return $this
     */
    public function setPageId($pageId)
    {
        $this->_pageId = $pageId;
        return $this;
    }

    /**
     * @param int $repeaterId
     * @param int $rowId
     * @return $this
     */
    public function setRepeaterData($repeaterId, $rowId)
    {
        $this->_repeaterId = $repeaterId;
        $this->_repeaterRowId = $rowId;
        return $this;
    }

    /**
     * @return int
     */
    public function getVersionId()
    {
        return $this->_versionId;
    }

    /**
     * @return int
     */
    public function getPageId()
    {
        return $this->_pageId;
    }

    /**
     * @return int
     */
    public function getRepeaterId()
    {
        return $this->_repeaterRowId;
    }

    /**
     * @return int
     */
    public function getRepeaterRowId()
    {
        return $this->_repeaterId;
    }

    /**
     * @param string $content
     */
    public function updateContent($content)
    {
        if (!$this->_versionId) {
            $this->_versionId = PageVersion::add_new($this->_pageId);
        }
        if ($this->_repeaterId && $this->_repeaterRowId) {
            PageBlockRepeaterData::updateBlockData($content, $this->id, $this->_versionId, $this->_repeaterId, $this->_repeaterRowId);
        } elseif ($this->_pageId) {
            PageBlock::updateBlockData($content, $this->id, $this->_versionId, $this->_pageId);
        } else {
            PageBlockDefault::updateBlockData($content, $this->id, $this->_versionId);
        }
    }

    /**
     * @return string
     */
    public function getContent()
    {
        if ($this->_repeaterId && $this->_repeaterRowId) {
            $blockData = PageBlockRepeaterData::getBlockData($this->id, $this->_versionId, $this->_repeaterId, $this->_repeaterRowId);
        } elseif ($this->_pageId) {
            $blockData = PageBlock::getBlockData($this->id, $this->_versionId, $this->_pageId);
        } else {
            $blockData = PageBlockDefault::getBlockData($this->id, $this->_versionId);
        }
        return $blockData;
    }

    /**
     * @param string $content
     * @return bool
     */
    public function publishContent($content)
    {
        if ($this->_pageId) {
            PageSearchData::updateText($content, $this->id, $this->_pageId);
            $pageVersion = PageVersion::get_live_version($this->_pageId);
            if ($this->_versionId != $pageVersion->version_id) {
                return $pageVersion->publish();
            }
        }
        return false;
    }

    /**
     * @param Eloquent $model
     * @param int $version (select specific version or alternatively -1 for live version 0 & for latest version)
     * @param array $filter_on
     * @param array $filter_values
     * @param string $order_by
     * @return array
     * @throws \Exception
     */
    public static function getDataForVersion($model, $version, $filter_on = [], $filter_values = [], $order_by = '')
    {
        $parameters = [];
        $where_qs['j'] = [];
        $where_qs['main'] = ['main.content != ""'];
        if (!empty($filter_on) && !empty($filter_values)) {
            $parameters = [];
            foreach ($filter_values as $k => $filter_value) {
                if (!empty($filter_value)) {
                    if (is_array($filter_value)) {
                        $i = 1;
                        $vars1 = [];
                        $vars2 = [];
                        foreach ($filter_value as $value) {
                            $parameters['fid1_' . $k . $i] = $value;
                            $vars1[] = ':fid1_' . $k . $i;
                            $parameters['fid2_' . $k . $i] = $value;
                            $vars2[] = ':fid2_' . $k . $i;
                            $i++;
                        }
                        $eq1 = "IN (" . implode(", ", $vars1) . ")";
                        $eq2 = "IN (" . implode(", ", $vars2) . ")";

                    } else {
                        $parameters['fid1' . $k] = $filter_value;
                        $parameters['fid2' . $k] = $filter_value;
                        $eq1 = '= :fid1' . $k;
                        $eq2 = '= :fid2' . $k;
                    }
                    $where_qs['j'][] = 'inr.' . $filter_on[$k] . ' ' . $eq1;
                    $where_qs['main'][] = 'main.' . $filter_on[$k] . ' ' . $eq2;
                } else {
                    return null;
                }
            }
        }
        if (!empty($version) && $version > 0) {
            $where_qs['j'][] = 'version <= :version';
            $parameters['version'] = $version;
        }
        foreach ($where_qs as $k => $where_q) {
            if (!empty($where_q)) {
                $where_qs[$k] = 'where ' . implode(' and ', $where_q);
            } else {
                $where_qs[$k] = '';
            }
        }
        $on_clause = 'main.version = j.version';
        $max_live = '';
        $table_name = $model->getTable();
        $full_table_name = DB::getTablePrefix() . $table_name;
        $pageLangTable = DB::getTablePrefix().(new PageLang)->getTable();
        switch ($table_name) {
            case 'page_blocks':
                $max_live = ($version == -1) ? 'join '.$pageLangTable.' pl on pl.page_id = inr.page_id and pl.language_id = inr.language_id and pl.live_version >= inr.version' : '';
                $identifiers = ['block_id', 'language_id', 'page_id'];
                break;
            case 'page_blocks_default':
                $identifiers = ['block_id', 'language_id'];
                break;
            case 'page_blocks_repeater_data':
                $identifiers = ['block_id', 'row_key'];
                break;
            default:
                throw new \Exception('unknown blocks data table: ' . $full_table_name);
        }
        foreach ($identifiers as $identifier) {
            $on_clause .= ' and main.' . $identifier . ' = j.' . $identifier;
        }
        $select_identifiers = 'inr.' . implode(', inr.', $identifiers);
        $order_by = !empty($order_by) ? ' order by main.' . $order_by : '';
        $correct_versions_query = "
            select main.* from " . $full_table_name . " main
            inner join(
                select " . $select_identifiers . ", max(inr.version) version from " . $full_table_name . " inr " . $max_live . "
                " . $where_qs['j'] . "
                group by " . $select_identifiers . "
            ) j on " . $on_clause . "
            " . $where_qs['main'] . $order_by;
        return DB::select(DB::raw($correct_versions_query), $parameters);
    }

}
