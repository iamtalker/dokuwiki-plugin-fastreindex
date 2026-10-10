<?php
/**
 * Fast Reindex plugin: AJAX backend.
 * Calls DokuWiki's own indexer functions directly (idx_addPage / Indexer::addMetaKeys).
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */
class action_plugin_fastreindex extends DokuWiki_Action_Plugin
{
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'handleAjax');
    }

    public function handleAjax(Doku_Event $event, $param)
    {
        if ($event->data !== 'plugin_fastreindex') return;
        $event->preventDefault();
        $event->stopPropagation();

        global $INPUT, $conf;
        @set_time_limit(0);
        @ignore_user_abort(true);
        header('Content-Type: application/json; charset=utf-8');

        if (!auth_isadmin() || !checkSecurityToken()) {
            http_response_code(403);
            echo json_encode(['error' => 'access denied']);
            return;
        }

        $do = $INPUT->post->str('do');

        if ($do === 'pagelist') {
            $data = [];
            search($data, $conf['datadir'], 'search_allpages', []);
            echo json_encode(array_column($data, 'id'), JSON_UNESCAPED_UNICODE);
            return;
        }

        $pages = json_decode($INPUT->post->str('pages', '[]'), true);
        if (!is_array($pages)) $pages = [];

        if ($do === 'tags') {
            echo json_encode($this->indexTags($pages), JSON_UNESCAPED_UNICODE);
            return;
        }
        if ($do === 'full') {
            echo json_encode($this->indexFull($pages), JSON_UNESCAPED_UNICODE);
            return;
        }

        echo json_encode(['error' => 'unknown call']);
    }

    /**
     * Update only the tag (subject) metadata index.
     */
    protected function indexTags(array $pages)
    {
        $Indexer = idx_get_indexer();
        $tagHelper = plugin_load('helper', 'tag');
        $res = [];
        foreach ($pages as $p) {
            if (!page_exists($p)) {
                $res[$p] = 'skip';
                continue;
            }
            $raw = rawWiki($p);
            $meta = p_get_metadata($p, 'subject', METADATA_RENDER_UNLIMITED);
            if (strpos($raw, '{{tag>') === false && empty($meta)) {
                $res[$p] = 'skip';
                continue;
            }
            $tags = is_array($meta) ? $meta : [];
            if ($tagHelper) $tags = array_values($tagHelper->cleanTagList($tags));
            $r = $Indexer->addMetaKeys($p, ['subject' => $tags]);
            $res[$p] = ($r === true) ? 'ok' : 'fail';
        }
        return $res;
    }

    /**
     * Full re-index of changed pages (fulltext + metadata).
     */
    protected function indexFull(array $pages)
    {
        $ver = idx_get_version();
        $res = [];
        foreach ($pages as $p) {
            $tag = metaFN($p, '.indexed');
            if (file_exists($tag)
                && trim(io_readFile($tag)) == $ver
                && @filemtime($tag) > @filemtime(wikiFN($p))) {
                $res[$p] = 'skip';
                continue;
            }
            $r = idx_addPage($p, false, true);
            $res[$p] = ($r === true) ? 'ok' : 'fail';
        }
        return $res;
    }
}
