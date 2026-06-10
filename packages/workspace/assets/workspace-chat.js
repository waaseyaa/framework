/*
 * Waaseyaa workspace chat client.
 *
 * Extracted from the Anokii Identity companion chat and parameterized so any
 * surface (and any consumer app) can mount it. Speaks the workspace chat
 * transport contract (docs/specs/workspace-chat-surface.md): SSE events
 * meta / delta / done / proposal / applied over a fetch()ed stream, plus a
 * paginated messages endpoint for rehydration.
 *
 * window.WaaseyaaChat.mount(root, config) -> controller | null
 *
 * The root element must contain:
 *   [data-ws-stream]   the scrollable message column
 *   [data-ws-form]     the composer <form>
 *   [data-ws-input]    the composer <textarea>
 *   [data-ws-send]     the submit button
 * Optional: [data-ws-new] (new-chat button), [data-ws-chips] (starter chips),
 *           [data-ws-counter] (character counter), [data-ws-intro] (intro turn).
 *
 * config:
 *   endpoints: { send, apply, messages(id) -> url }            (required)
 *   thread:    { key, initial }  per-ACCOUNT storage key (interpolate the uid
 *              server-side, e.g. 'ws.chat.anokii.7'); `initial` is a
 *              server-provided thread id (?c= deep link) and takes precedence
 *              over the stored key for this view. It is persisted only after
 *              the messages endpoint validates it.                (required)
 *   user:      { id, label }     the signed-in account; id drives viewer-mode
 *              proposal rendering.                             (required)
 *   limit:     max question length; mirrored on the textarea and the counter,
 *              and refreshed from a rejection's machine-readable `limit`.
 *   pageSize:  rehydration page size (default 30).
 *   intro:     html for a fresh thread's intro bubble.
 *   chips:     [] of starter prompts (used by New chat).
 *   avatars:   { user, ai } avatar glyphs (default YOU / AI).
 *   proposalRouter(d, api): return true if the proposal was routed somewhere
 *              outside the conversation (e.g. onto an entity card). api =
 *              { inlineCard, finalize, lead, decide, addMsg, fmt } —
 *              finalize(html) closes out the streaming bubble with the
 *              formatted lead plus optional extra html (the donor's
 *              "I drafted this on the X pillar" pointer pattern).
 *   refreshStrategy(info): called after an applied change. Default: a quiet
 *              "Change applied." notice. Identity passes a reload here.
 *   md:        a WaaseyaaMd instance (default: window.WaaseyaaMd).
 *   snapshot:  sessionStorage repaint-avoidance (default true).
 */
(function () {
  'use strict';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function mount(root, cfg) {
    if (!root || !cfg || !cfg.endpoints || !cfg.thread || !cfg.user) { return null; }
    var stream = root.querySelector('[data-ws-stream]');
    var form = root.querySelector('[data-ws-form]');
    var input = root.querySelector('[data-ws-input]');
    var sendBtn = root.querySelector('[data-ws-send]');
    var newBtn = root.querySelector('[data-ws-new]');
    var chips = root.querySelector('[data-ws-chips]');
    var counter = root.querySelector('[data-ws-counter]');
    if (!stream || !form || !input || !sendBtn) { return null; }

    var md = cfg.md || window.WaaseyaaMd || null;
    var KEY = cfg.thread.key;
    var SNAPKEY = KEY + '.snap';
    var pageSize = cfg.pageSize || 30;
    var limit = cfg.limit || 0;
    var useSnapshot = cfg.snapshot !== false;
    var avatars = cfg.avatars || {};
    var avUser = avatars.user || 'YOU';
    var avAi = avatars.ai || 'AI';
    var cid = 0, busy = false, oldestId = 0, hasMore = false;

    function fmt(t) {
      if (md && md.render) { return md.render(t); }
      return '<p>' + esc(t).replace(/\n/g, '<br>') + '</p>';
    }
    function fmtVal(v) { if (v === null || v === undefined || v === '') { return '(none)'; } if (typeof v === 'object') { return JSON.stringify(v); } var s = String(v); return s.length > 140 ? s.slice(0, 137) + '...' : s; }

    function rememberCid(id) { cid = id; try { localStorage.setItem(KEY, String(id)); } catch (e) {} }
    function forgetCid() { cid = 0; try { localStorage.removeItem(KEY); sessionStorage.removeItem(SNAPKEY); } catch (e) {} }

    function addMsg(role, opts) {
      opts = opts || {};
      var m = document.createElement('div');
      m.className = 'imsg ' + (role === 'user' ? 'user' : 'ai') + (opts.pending ? ' pending' : '');
      var a = document.createElement('div'); a.className = 'av'; a.textContent = role === 'user' ? avUser : avAi;
      var b = document.createElement('div'); b.className = 'ibub';
      m.appendChild(a); m.appendChild(b);
      var target = (opts && opts.into) || stream;
      target.appendChild(m);
      if (target === stream) { stream.scrollTop = stream.scrollHeight; }
      return b;
    }

    // A failed user turn keeps its text, shows the server's reason, and
    // restores the composer (when it is still empty) so nothing typed is
    // silently lost.
    function markFailed(bub, q, reason) {
      var wrap = bub.closest('.imsg');
      if (wrap) { wrap.classList.remove('pending'); wrap.classList.add('failed'); }
      var note = document.createElement('div');
      note.className = 'ws-fail';
      note.textContent = reason || 'Not sent.';
      bub.appendChild(note);
      if (input.value.trim() === '') { input.value = q; autosize(); updateCounter(); }
    }
    function markSent(bub) {
      var wrap = bub.closest('.imsg');
      if (wrap) { wrap.classList.remove('pending'); }
    }

    // A length rejection carries the server's machine-readable limit; adopt
    // it so a stale client self-corrects (spec section 1.1).
    function adoptLimit(serverLimit) {
      var n = parseInt(String(serverLimit), 10) || 0;
      if (n > 0) { limit = n; input.setAttribute('maxlength', String(n)); updateCounter(); }
    }

    function srcLine(sources) {
      if (!sources || !sources.length || !sources.map) { return ''; }
      var pills = sources.map(function (s) { return '<span class="src">' + esc((s && (s.title || s.source_url)) || '') + '</span>'; }).join('');
      return '<div class="srcline">Sources ' + pills + '</div>';
    }

    function frames(buf, on) {
      var parts = buf.split('\n\n');
      var rest = parts.pop();
      parts.forEach(function (bl) {
        var ev = 'message', data = '';
        bl.split('\n').forEach(function (l) {
          if (l.indexOf('event:') === 0) { ev = l.slice(6).trim(); }
          else if (l.indexOf('data:') === 0) { data += l.slice(5).trim(); }
        });
        if (data) { try { on(ev, JSON.parse(data)); } catch (e) {} }
      });
      return rest;
    }

    function send(q) {
      q = (q || input.value || '').trim();
      if (!q || busy) { return; }
      removeChips();
      input.value = ''; autosize(); updateCounter();
      var userBub = addMsg('user', { pending: true });
      userBub.textContent = q;
      var bub = addMsg('ai');
      bub.innerHTML = '<span class="iwork"><span class="d"></span>Thinking...</span>';
      consume(cfg.endpoints.send, { question: q, conversation_id: cid }, bub, { userBub: userBub, q: q });
    }

    function consume(url, body, bub, sent) {
      busy = true; sendBtn.disabled = true;
      var raw = '', proposed = false, started = false, acked = false, didApply = false, appliedInfo = null, sources = null;
      fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'text/event-stream' }, credentials: 'same-origin', body: JSON.stringify(body) })
        .then(function (r) {
          if (!r.ok) {
            // A JSON rejection (over-limit 422, ownership 403, stale 404):
            // surface the server's reason instead of pretending otherwise.
            return r.json().catch(function () { return null; }).then(function (j) {
              if (j && j.limit) { adoptLimit(j.limit); }
              var reason = (j && (j.error || j.message)) || ('Request failed (HTTP ' + r.status + ').');
              throw { wsReason: reason };
            });
          }
          if (!r.body) { throw { wsReason: 'No response stream.' }; }
          var rd = r.body.getReader(), dec = new TextDecoder(), buf = '';
          function pump() {
            return rd.read().then(function (res) {
              if (res.done) { fin(); return undefined; }
              buf += dec.decode(res.value, { stream: true });
              buf = frames(buf, function (ev, d) {
                if (ev === 'meta') { acked = true; if (sent) { markSent(sent.userBub); } if (d.conversation_id) { rememberCid(d.conversation_id); } }
                else if (ev === 'delta') { if (!started) { bub.textContent = ''; started = true; } raw += d.text || ''; bub.textContent = raw; stream.scrollTop = stream.scrollHeight; }
                else if (ev === 'done') { sources = d.sources || null; }
                else if (ev === 'proposal') { proposed = true; routeProposal(bub, raw, d); }
                else if (ev === 'applied') { if (d.ok) { didApply = true; appliedInfo = d; } }
              });
              return pump();
            });
          }
          function fin() {
            if (!proposed) { bub.innerHTML = (fmt(raw) || '<p>Done.</p>') + srcLine(sources); }
            busy = false; sendBtn.disabled = false;
            stream.scrollTop = stream.scrollHeight;
            saveSnapshot();
            if (didApply) { onApplied(appliedInfo); }
          }
          return pump();
        })
        .catch(function (err) {
          busy = false; sendBtn.disabled = false;
          var reason = err && err.wsReason;
          if (sent && !acked) {
            // Never reached the server (or it refused): honest failure state.
            bub.closest('.imsg').remove();
            markFailed(sent.userBub, sent.q, reason);
            return;
          }
          if (proposed) { return; }
          if (reason) {
            // The server refused at the application level (e.g. an apply
            // decision on a foreign/stale proposal). Say so; nothing dropped.
            bub.innerHTML = '<p class="ws-fail">' + esc(reason) + '</p>';
            return;
          }
          // A genuine mid-stream drop: the conversation is persisted
          // server-side, so resync from it rather than dead-ending.
          if (cid) {
            bub.innerHTML = '<span class="iwork">Connection dropped. Resyncing...</span>';
            rehydrate(cid, { replace: true, onFail: function () { bub.innerHTML = '<p class="ws-fail">Connection dropped. Reload to retry.</p>'; } });
          } else {
            bub.innerHTML = '<p class="ws-fail">Connection dropped. Please try again.</p>';
          }
        });
    }

    function onApplied(info) {
      if (typeof cfg.refreshStrategy === 'function') { cfg.refreshStrategy(info || {}); return; }
      var n = addMsg('ai');
      n.innerHTML = '<p>Change applied.</p>';
      saveSnapshot();
    }

    // opts.live: a freshly streamed proposal (clears the send latch when the
    // card renders). Rehydrated/paginated cards must NOT touch the latch.
    function inlineCard(bub, lead, d, opts) {
      opts = opts || {};
      var mine = !d.proposer_uid || String(d.proposer_uid) === String(cfg.user.id);
      var h = lead ? fmt(lead) : '';
      h += '<div class="iprop' + (d.destructive ? ' danger' : '') + '"><div class="ph">Proposed change' + (d.destructive ? ' (delete)' : '') + '</div><div class="ps">' + esc(d.summary || '') + '</div>';
      if (d.diff && d.diff.length) {
        h += '<ul class="pd">';
        d.diff.forEach(function (x) {
          h += '<li><span class="f">' + esc(x.field) + '</span><span class="b">' + esc(fmtVal(x.before)) + '</span><span>&rarr;</span><span class="a">' + esc(fmtVal(x.after)) + '</span></li>';
        });
        h += '</ul>';
      }
      if (mine && !opts.readonly) {
        h += '<div class="pact"><button type="button" class="ipb ok">Approve</button><button type="button" class="ipb no">Reject</button></div>';
      } else {
        h += '<div class="pact"><span class="ws-awaiting">Awaiting ' + esc(d.proposer_label || 'its proposer') + '</span></div>';
      }
      h += '</div>';
      bub.innerHTML = h;
      if (opts.live) { busy = false; sendBtn.disabled = false; }
      var c = bub.querySelector('.iprop');
      if (mine && !opts.readonly) {
        c.querySelector('.ok').addEventListener('click', function () { decide(c, d.token, 'approve'); });
        c.querySelector('.no').addEventListener('click', function () { decide(c, d.token, 'reject'); });
      }
      if (opts.live) { stream.scrollTop = stream.scrollHeight; }
    }

    function decide(c, token, decision) {
      if (busy) { return; }
      [].slice.call(c.querySelectorAll('.ipb')).forEach(function (b) { b.disabled = true; });
      var bub = addMsg('ai');
      bub.innerHTML = '<span class="iwork"><span class="d"></span>' + (decision === 'approve' ? 'Applying...' : 'Cancelling...') + '</span>';
      consume(cfg.endpoints.apply, { token: token, decision: decision }, bub, null);
    }

    function routeProposal(bub, lead, d) {
      if (typeof cfg.proposalRouter === 'function') {
        var api = {
          lead: lead,
          fmt: fmt,
          addMsg: addMsg,
          decide: decide,
          inlineCard: function (dd, opts) { inlineCard(bub, lead, dd || d, opts || { live: true }); },
          // Close out the streaming bubble: formatted lead + optional extra
          // html (the donor's "I drafted this on the X pillar" pointer).
          finalize: function (html) { bub.innerHTML = (lead ? fmt(lead) : '') + (html || ''); }
        };
        var handled = false;
        try { handled = !!cfg.proposalRouter(d, api); } catch (e) {}
        if (handled) { busy = false; sendBtn.disabled = false; stream.scrollTop = stream.scrollHeight; return; }
      }
      inlineCard(bub, lead, d, { live: true });
    }

    // ---- composer ----------------------------------------------------------

    function autosize() { input.style.height = '42px'; input.style.height = Math.min(input.scrollHeight, 140) + 'px'; }
    function updateCounter() {
      if (!counter || !limit) { return; }
      var n = (input.value || '').length;
      if (n >= limit * 0.8) { counter.textContent = n + ' / ' + limit; counter.classList.add('on'); counter.classList.toggle('over', n > limit); }
      else { counter.textContent = ''; counter.classList.remove('on', 'over'); }
    }
    if (limit) { input.setAttribute('maxlength', String(limit)); }
    form.addEventListener('submit', function (e) { e.preventDefault(); send(); });
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } });
    input.addEventListener('input', function () { autosize(); updateCounter(); });

    function removeChips() { if (chips) { chips.remove(); chips = null; } }
    if (chips) {
      [].slice.call(chips.querySelectorAll('[data-ws-chip]')).forEach(function (b) {
        b.addEventListener('click', function () { send(b.textContent); });
      });
    }

    // ---- rehydration, pagination, snapshot ---------------------------------

    function renderTurn(m, into) {
      var bub = addMsg(m.role === 'user' ? 'user' : 'ai', { into: into });
      if (m.role === 'user') { bub.textContent = m.content; }
      else { bub.innerHTML = (fmt(m.content) || '<p>...</p>') + srcLine(m.sources); }
      if (m.proposal && m.proposal.status === 'pending') {
        inlineCard(bub, m.content, m.proposal);
      }
      return bub;
    }

    function clearStream() { stream.innerHTML = ''; oldestId = 0; hasMore = false; }

    function renderIntro() {
      var bub = addMsg('ai');
      var wrap = bub.closest('.imsg'); if (wrap) { wrap.setAttribute('data-ws-intro', ''); }
      bub.innerHTML = cfg.intro || 'New thread.';
    }

    function loadEarlierBar() {
      var bar = document.createElement('button');
      bar.type = 'button';
      bar.className = 'ws-earlier';
      bar.textContent = 'Load earlier messages';
      bar.addEventListener('click', function () {
        if (busy) { return; }
        bar.remove();
        fetchPage(cid, oldestId, function (d) { prependTurns(d); });
      });
      stream.insertBefore(bar, stream.firstChild);
    }

    function prependTurns(d) {
      var anchor = stream.firstChild;
      var keepHeight = stream.scrollHeight;
      var keepTop = stream.scrollTop;
      var hold = document.createElement('div');
      try {
        (d.messages || []).forEach(function (m) { renderTurn(m, hold); });
      } catch (e) {}
      var frag = document.createDocumentFragment();
      while (hold.firstChild) { frag.appendChild(hold.firstChild); }
      stream.insertBefore(frag, anchor);
      oldestId = d.oldest || oldestId; hasMore = !!d.has_more;
      if (hasMore) { loadEarlierBar(); }
      stream.scrollTop = keepTop + (stream.scrollHeight - keepHeight);
    }

    function fetchPage(id, before, onOk, onFail) {
      var url = cfg.endpoints.messages(id) + '?limit=' + pageSize + (before ? '&before=' + encodeURIComponent(before) : '');
      fetch(url, { credentials: 'same-origin' })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (d && d.ok) { onOk(d); }
          else if (onFail) { onFail(); }
        })
        .catch(function () { if (onFail) { onFail(); } });
    }

    function rehydrate(id, opts) {
      opts = opts || {};
      if (!id) { return; }
      fetchPage(id, 0, function (d) {
        // Never clobber an in-flight turn: a background resync that returns
        // while a send is streaming just stands down (the next fin()
        // snapshot supersedes it).
        if (busy && opts.replace) { return; }
        cid = id;
        rememberCid(id); // persisted only now, after the server validated it
        if (opts.replace) { clearStream(); }
        if (!d.messages || !d.messages.length) {
          if (opts.replace) { renderIntro(); }
          return;
        }
        var intro = root.querySelector('[data-ws-intro]'); if (intro) { intro.remove(); }
        removeChips();
        d.messages.forEach(function (m) { renderTurn(m); });
        oldestId = d.oldest || 0; hasMore = !!d.has_more;
        if (hasMore) { loadEarlierBar(); }
        stream.scrollTop = stream.scrollHeight;
        saveSnapshot();
      }, function () {
        if (opts.onFail) { opts.onFail(); return; }
        // The id did not validate. Forget it only if it was the stored
        // pointer; a bad deep link must not destroy a good stored thread.
        if (opts.forgetOnFail) { forgetCid(); }
        if (opts.fallback) { rehydrate(opts.fallback, { replace: true, forgetOnFail: true }); }
      });
    }

    function saveSnapshot() {
      if (!useSnapshot || !cid) { return; }
      try {
        var html = stream.innerHTML;
        if (html.length < 250000) { sessionStorage.setItem(SNAPKEY, JSON.stringify({ cid: cid, html: html })); }
      } catch (e) {}
    }
    function restoreSnapshot(id) {
      if (!useSnapshot) { return false; }
      try {
        var raw = sessionStorage.getItem(SNAPKEY);
        if (!raw) { return false; }
        var snap = JSON.parse(raw);
        if (!snap || snap.cid !== id || !snap.html) { return false; }
        var intro = root.querySelector('[data-ws-intro]'); if (intro) { intro.remove(); }
        removeChips();
        stream.innerHTML = snap.html;
        // Snapshot buttons carry no listeners; the mandatory background
        // resync below re-renders and re-arms them.
        stream.scrollTop = stream.scrollHeight;
        return true;
      } catch (e) { return false; }
    }

    function newChat() {
      if (busy) { return; }
      forgetCid();
      clearStream();
      renderIntro();
      if (cfg.chips && cfg.chips.length) {
        var c = document.createElement('div');
        c.className = 'cw-chips';
        c.setAttribute('data-ws-chips', '');
        cfg.chips.forEach(function (label) {
          var b = document.createElement('button');
          b.type = 'button'; b.className = 'cw-chip'; b.setAttribute('data-ws-chip', '');
          b.textContent = label;
          b.addEventListener('click', function () { send(label); });
          c.appendChild(b);
        });
        stream.appendChild(c);
        chips = c;
      }
      input.focus();
    }
    if (newBtn) { newBtn.addEventListener('click', newChat); }

    // ---- start-up: ?c= deep link wins over the stored per-account key ------
    // Neither id is trusted until the messages endpoint validates it; a bad
    // deep link falls back to the stored thread instead of destroying it.

    var initialId = cfg.thread.initial ? (parseInt(String(cfg.thread.initial), 10) || 0) : 0;
    var storedId = 0; try { storedId = parseInt(localStorage.getItem(KEY) || '0', 10) || 0; } catch (e) {}
    var startId = initialId || storedId;
    if (startId > 0) {
      var painted = restoreSnapshot(startId);
      rehydrate(startId, {
        replace: painted,
        forgetOnFail: startId === storedId,
        fallback: (initialId && storedId && storedId !== initialId) ? storedId : 0
      });
    }

    return { send: send, newChat: newChat, rehydrate: rehydrate, threadId: function () { return cid; } };
  }

  window.WaaseyaaChat = { mount: mount };
})();
