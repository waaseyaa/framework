/*
 * Waaseyaa workspace chat markdown renderer.
 *
 * Extracted from the Anokii chat surfaces (anokii-md.js) and parameterized:
 * nothing app-specific is hardcoded. The agent streams Markdown; this turns
 * it into clean HTML for a chat bubble. Safety: the whole input is
 * HTML-escaped FIRST, then markdown is applied to the escaped text, so no
 * model output can inject markup. Links are limited to http(s) or
 * root-relative.
 *
 * Hooks (per instance, via WaaseyaaMd.create(opts) or WaaseyaaMd.configure):
 *   statusVocab: array of lowercase status names. Table cells whose trimmed
 *     text matches a name render as <span class="stp stp-<name>"> pills.
 *     Default: [] (no pills — the pillar statuses live in the app, not here).
 *   linkPolicy(url): returns one of 'blank' | 'top' | 'panel' | 'drop'.
 *     NOTE: url arrives HTML-ESCAPED (the renderer escapes the whole input
 *     first), so e.g. ?a=1&b=2 reads as ?a=1&amp;b=2 — policies doing raw
 *     substring matching must account for entities.
 *     'blank'  -> target="_blank" rel="noopener"
 *     'top'    -> plain same-window link
 *     'panel'  -> no target, marked data-ws-open="panel" for the workspace
 *                 preview-panel interceptor (a later cut wires it; until
 *                 then panel links behave as 'top')
 *     'drop'   -> render the link text only
 *     Default: http(s) external -> 'blank'; root-relative -> 'top';
 *     anything else -> 'drop' (same behavior the donor had, minus its
 *     blanket new-tab treatment of internal links).
 *
 * Supports: headings, horizontal rules, ordered/unordered lists, tables,
 * bold, italic, inline code, links, and blank-line paragraphs.
 *
 * window.WaaseyaaMd.render(text) -> html string (default instance).
 */
(function () {
  'use strict';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  function defaultLinkPolicy(u) {
    if (/^https?:\/\//.test(u)) { return 'blank'; }
    if (u.charAt(0) === '/') { return 'top'; }
    return 'drop';
  }

  function create(opts) {
    opts = opts || {};
    var vocab = {};
    (opts.statusVocab || []).forEach(function (s) { vocab[String(s).toLowerCase()] = 1; });
    var linkPolicy = typeof opts.linkPolicy === 'function' ? opts.linkPolicy : defaultLinkPolicy;

    // Inline spans, applied to ALREADY-ESCAPED text.
    function inline(s) {
      s = s.replace(/`([^`]+)`/g, function (m, c) { return '<code>' + c + '</code>'; });
      s = s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, function (m, t, u) {
        var mode;
        try { mode = linkPolicy(u); } catch (e) { mode = 'drop'; }
        if (mode === 'blank') { return '<a href="' + u + '" target="_blank" rel="noopener">' + t + '</a>'; }
        if (mode === 'top') { return '<a href="' + u + '">' + t + '</a>'; }
        if (mode === 'panel') { return '<a href="' + u + '" data-ws-open="panel">' + t + '</a>'; }
        return t;
      });
      s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
      s = s.replace(/(^|[^*])\*([^*\n]+)\*(?!\*)/g, '$1<em>$2</em>');
      s = s.replace(/(^|[^_\w])_([^_\n]+)_(?![_\w])/g, '$1<em>$2</em>');
      return s;
    }

    function tableCell(text) {
      var key = text.trim().toLowerCase();
      if (Object.prototype.hasOwnProperty.call(vocab, key)) {
        return '<span class="stp stp-' + key + '">' + inline(text.trim()) + '</span>';
      }
      return inline(text.trim());
    }

    function isTableSep(line) {
      return /^\s*\|?\s*:?-{1,}:?\s*(\|\s*:?-{1,}:?\s*)+\|?\s*$/.test(line);
    }
    function splitRow(line) {
      return line.trim().replace(/^\|/, '').replace(/\|$/, '').split('|').map(function (c) { return c.trim(); });
    }
    function headingTag(level) {
      return level <= 2 ? 'h4' : (level === 3 ? 'h5' : 'h6');
    }

    function render(text) {
      var src = esc(text == null ? '' : String(text));
      var lines = src.split(/\n/);
      var out = [], i = 0, para = [], list = null;

      function flushP() { if (para.length) { out.push('<p>' + inline(para.join(' ')) + '</p>'); para = []; } }
      function flushL() {
        if (list) {
          out.push('<' + list.tag + '>' + list.items.map(function (it) { return '<li>' + inline(it) + '</li>'; }).join('') + '</' + list.tag + '>');
          list = null;
        }
      }
      function flush() { flushL(); flushP(); }

      while (i < lines.length) {
        var t = lines[i].trim();

        if (t === '') { flush(); i++; continue; }

        if (/^(\*\s*){3,}$/.test(t) || /^(-\s*){3,}$/.test(t) || /^(_\s*){3,}$/.test(t)) {
          flush(); out.push('<hr>'); i++; continue;
        }

        var hm = /^(#{1,6})\s+(.*)$/.exec(t);
        if (hm) { flush(); var tag = headingTag(hm[1].length); out.push('<' + tag + ' class="amh">' + inline(hm[2].trim()) + '</' + tag + '>'); i++; continue; }

        // Table: a pipe row followed by a |---|---| separator row.
        if (t.indexOf('|') > -1 && i + 1 < lines.length && isTableSep(lines[i + 1])) {
          flush();
          var head = splitRow(lines[i]);
          i += 2;
          var rows = [];
          while (i < lines.length && lines[i].trim() !== '' && lines[i].indexOf('|') > -1) { rows.push(splitRow(lines[i])); i++; }
          var thead = '<thead><tr>' + head.map(function (c) { return '<th>' + inline(c) + '</th>'; }).join('') + '</tr></thead>';
          var tbody = '<tbody>' + rows.map(function (r) {
            return '<tr>' + r.map(function (c) { return '<td>' + tableCell(c) + '</td>'; }).join('') + '</tr>';
          }).join('') + '</tbody>';
          out.push('<table class="amt">' + thead + tbody + '</table>');
          continue;
        }

        var um = /^[-*+]\s+(.*)$/.exec(t);
        if (um) { flushP(); if (!list || list.tag !== 'ul') { flushL(); list = { tag: 'ul', items: [] }; } list.items.push(um[1]); i++; continue; }

        var om = /^\d+[.)]\s+(.*)$/.exec(t);
        if (om) { flushP(); if (!list || list.tag !== 'ol') { flushL(); list = { tag: 'ol', items: [] }; } list.items.push(om[1]); i++; continue; }

        flushL(); para.push(t); i++;
      }
      flush();

      return '<div class="amd">' + (out.join('') || '<p>' + inline(src) + '</p>') + '</div>';
    }

    return { render: render, esc: esc };
  }

  var instance = create({});
  window.WaaseyaaMd = {
    create: create,
    configure: function (opts) { instance = create(opts); },
    render: function (text) { return instance.render(text); },
    esc: esc
  };
})();
