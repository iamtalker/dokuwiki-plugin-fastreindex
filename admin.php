<?php
/**
 * Fast Reindex plugin: admin screen.
 *
 * @license GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */
class admin_plugin_fastreindex extends DokuWiki_Admin_Plugin
{
    public function getMenuSort()
    {
        return 41;
    }

    public function forAdminOnly()
    {
        return true;
    }

    public function getMenuText($language)
    {
        return $this->getLang('menu');
    }

    public function handle()
    {
    }

    public function html()
    {
        $i18n = [];
        foreach (['fetching', 'denied', 'indexing', 'retrying', 'stopped', 'done', 'min', 'sec'] as $k) {
            $i18n[$k] = $this->getLang($k);
        }
        $cfg = json_encode([
            'url'    => DOKU_BASE . 'lib/exe/ajax.php',
            'sectok' => getSecurityToken(),
            'i18n'   => $i18n,
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);

        echo '<h1>' . hsc($this->getLang('heading')) . '</h1>';
        echo '<p>' . $this->getLang('intro') . '</p>';
        echo '<div id="fri_box">';
        echo '<p><button type="button" class="button" id="fri_start">' . hsc($this->getLang('start')) . '</button> ';
        echo '<button type="button" class="button" id="fri_stop" disabled>' . hsc($this->getLang('stop')) . '</button></p>';
        echo '<p><label><input type="radio" name="fri_mode" value="tags" checked> ' . $this->getLang('mode_tags') . '</label><br>';
        echo '<label><input type="radio" name="fri_mode" value="full"> ' . $this->getLang('mode_full') . '</label></p>';
        echo '<div style="background:#ddd;height:16px;border-radius:8px;overflow:hidden;margin:12px 0;max-width:520px">';
        echo '<div id="fri_bar" style="background:#2a9d4b;height:100%;width:0"></div></div>';
        echo '<table class="inline">';
        echo '<tr><th>' . hsc($this->getLang('total')) . '</th><td id="fri_total">-</td></tr>';
        echo '<tr><th>' . hsc($this->getLang('processed')) . '</th><td id="fri_done">0</td></tr>';
        echo '<tr><th>' . hsc($this->getLang('updated')) . '</th><td id="fri_ok">0</td></tr>';
        echo '<tr><th>' . hsc($this->getLang('skipped')) . '</th><td id="fri_skip">0</td></tr>';
        echo '<tr><th>' . hsc($this->getLang('failed')) . '</th><td id="fri_fail">0</td></tr>';
        echo '<tr><th>' . hsc($this->getLang('time')) . '</th><td id="fri_time">-</td></tr>';
        echo '</table>';
        echo '<p id="fri_cur" style="color:#777;font-size:90%"></p>';
        echo '<p id="fri_msg"></p>';
        echo '</div>';
        echo '<script>';
        echo 'window.FRI_CFG = ' . $cfg . ';';
        echo <<<'JS'
(function () {
  var cfg = window.FRI_CFG;
  var t = cfg.i18n;
  var $ = function (id) { return document.getElementById(id); };
  var running = false, stopFlag = false;
  // minimal sprintf for %d and %1$d style placeholders
  function fmtMsg(s) {
    var a = Array.prototype.slice.call(arguments, 1), i = 0;
    return s.replace(/%(?:(\d)\$)?d/g, function (m, n) { return a[n ? parseInt(n, 10) - 1 : i++]; });
  }
  function post(params) {
    params.call = 'plugin_fastreindex';
    params.sectok = cfg.sectok;
    var body = Object.keys(params).map(function (k) {
      return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
    }).join('&');
    return fetch(cfg.url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body
    }).then(function (r) {
      if (r.status === 403) throw new Error('forbidden');
      return r.json();
    });
  }
  function fmt(sec) {
    sec = Math.max(0, Math.round(sec));
    return Math.floor(sec / 60) + t.min + ' ' + (sec % 60) + t.sec;
  }
  async function run() {
    running = true; stopFlag = false;
    $('fri_start').disabled = true; $('fri_stop').disabled = false;
    $('fri_msg').textContent = t.fetching;
    var pages;
    try {
      pages = await post({ do: 'pagelist' });
    } catch (e) {
      $('fri_msg').textContent = t.denied;
      $('fri_start').disabled = false; $('fri_stop').disabled = true; running = false;
      return;
    }
    var mode = document.querySelector('input[name=fri_mode]:checked').value;
    var BATCH = (mode === 'tags') ? 50 : 20;
    var total = pages.length, done = 0, ok = 0, skip = 0, failedList = [];
    var t0 = Date.now();
    $('fri_total').textContent = total;
    $('fri_msg').textContent = t.indexing;
    function update(cur) {
      $('fri_done').textContent = done;
      $('fri_ok').textContent = ok;
      $('fri_skip').textContent = skip;
      $('fri_fail').textContent = failedList.length;
      $('fri_bar').style.width = Math.min(100, done / total * 100).toFixed(1) + '%';
      var el = (Date.now() - t0) / 1000;
      var eta = done ? el / done * (total - done) : 0;
      $('fri_time').textContent = fmt(el) + ' / ' + fmt(eta);
      if (cur) $('fri_cur').textContent = cur;
    }
    async function pass(list, countDone) {
      var failed = [];
      for (var i = 0; i < list.length && !stopFlag; i += BATCH) {
        var chunk = list.slice(i, i + BATCH);
        var res;
        try {
          res = await post({ do: mode, pages: JSON.stringify(chunk) });
        } catch (e) {
          failed = failed.concat(chunk);
          if (countDone) done += chunk.length;
          update();
          continue;
        }
        chunk.forEach(function (p) {
          var r = res[p];
          if (r === 'ok') ok++;
          else if (r === 'skip') skip++;
          else failed.push(p);
        });
        if (countDone) done += chunk.length;
        failedList = failed;
        update(chunk[chunk.length - 1]);
      }
      return failed;
    }
    var failed = await pass(pages, true);
    if (!stopFlag && failed.length) {
      $('fri_msg').textContent = fmtMsg(t.retrying, failed.length);
      failedList = failed;
      failedList = await pass(failed, false);
    }
    update('');
    $('fri_msg').textContent = stopFlag ? t.stopped : fmtMsg(t.done, ok, skip, failedList.length);
    if (failedList.length) {
      $('fri_cur').textContent = failedList.slice(0, 100).join(', ') + (failedList.length > 100 ? ' ...' : '');
    }
    $('fri_start').disabled = false; $('fri_stop').disabled = true; running = false;
  }
  $('fri_start').onclick = function () { if (!running) run(); };
  $('fri_stop').onclick = function () { stopFlag = true; };
})();
JS;
        echo '</script>';
    }
}
