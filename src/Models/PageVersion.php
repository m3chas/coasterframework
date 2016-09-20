<?php namespace CoasterCms\Models;

use Auth;
use CoasterCms\Helpers\Cms\View\PaginatorRender;
use DateTimeHelper;
use Eloquent;
use View;

class PageVersion extends Eloquent
{

    protected $table = 'page_versions';
    protected static $_liveVersions = [];

    public function user()
    {
        return $this->belongsTo('CoasterCms\Models\User');
    }

    public function scheduled_versions()
    {
        return $this->hasMany('CoasterCms\Models\PageVersionSchedule')->orderBy('live_from');
    }

    public static function latest_version($page_id, $return_obj = false)
    {
        $version = self::where('page_id', '=', $page_id)->orderBy('version_id', 'desc')->first();
        if (!empty($version)) {
            return $return_obj ? $version : $version->version_id;
        }
        return 0;
    }

    public static function get_live_version($pageId)
    {
        if (empty(self::$_liveVersions)) {
            $pageLangTable = (new PageLang)->getTable();
            $pageVersionsTable = (new self)->getTable();
            $pageVersions = self::join($pageLangTable, function ($join) use($pageLangTable, $pageVersionsTable) {
                $join->on($pageLangTable.'.page_id', '=', $pageVersionsTable.'.page_id')->on($pageLangTable.'.live_version', '=', $pageVersionsTable.'.version_id');
            })->where('language_id', '=', Language::current())->orderBy($pageLangTable.'.page_id')->get([$pageVersionsTable.'.*']);
            foreach ($pageVersions as $pageVersion) {
                self::$_liveVersions[$pageVersion->page_id] = $pageVersion;
            }
        }
        return !empty(self::$_liveVersions[$pageId]) ? self::$_liveVersions[$pageId] : null;
    }

    public static function add_new($page_id, $label = null)
    {
        $page_version = new self;
        $page_version->page_id = $page_id;
        $page_version->version_id = self::latest_version($page_id) + 1;
        $page_version->template = !empty($page_id) ? Page::find($page_id)->template : 0;
        $page_version->label = $label;
        $page_version->preview_key = base_convert((rand(10, 99) . microtime(true)), 10, 36);
        $page_version->save();
        return $page_version;
    }
    
    public function publish($set_live = false, $ignore_auth = false)
    {
        $page_lang = PageLang::where('page_id', '=', $this->page_id)->where('language_id', '=', Language::current())->first();
        $page = Page::find($this->page_id);
        $publishingOn = (config('coaster::admin.publishing') > 0) ? true : false;
        $haveAuth = $ignore_auth || (($publishingOn && Auth::action('pages.version-publish', ['page_id' => $this->page_id])) || (!$publishingOn && Auth::action('pages.edit', ['page_id' => $this->page_id])));
        if (!empty($page_lang) && !empty($page) && $haveAuth) {
            $page_lang->live_version = $this->version_id;
            $page_lang->save();
            $page->template = $this->template;
            if ($set_live && $page->live == 0) {
                if (!empty($page->live_start) || !empty($page->live_end)) {
                    $page->live = 2;
                } else {
                    $page->live = 1;
                }
            }
            $page->save();
            return 1;
        }
        return 0;
    }

    public static function version_table($page_id)
    {
        $versionsQuery = static::with(['user', 'scheduled_versions'])->where('page_id', '=', $page_id)->orderBy('version_id', 'desc');
        $versions = $versionsQuery->paginate(15);
        $pagination = PaginatorRender::admin($versions);

        $page_lang = PageLang::where('page_id', '=', $page_id)->where('language_id', '=', Language::current())->first();
        $live_version = static::where('page_id', '=', $page_id)->where('version_id', '=', $page_lang ? $page_lang->live_version : 0)->first();
        $live_version = $live_version ?: new static;

        $can_publish = Auth::action('pages.version-publish', ['page_id' => $page_id]);

        return View::make('coaster::partials.tabs.versions.table', ['versions' => $versions, 'pagination' => $pagination, 'live_version' => $live_version, 'can_publish' => $can_publish])->render();
    }

    public function save(array $options = array())
    {
        $user = Auth::user();
        if (empty($options['system']) && !empty($user)) {
            $this->user_id = $user->id;
        } else {
            $this->user_id = 0;
        }
        return parent::save($options);
    }

    public function __get($key)
    {
        if ($key == 'name') {
            return parent::__get('label') ?: 'version '.DateTimeHelper::display($this->created_at, 'short');
        } else {
            return parent::__get($key);
        }
    }

}