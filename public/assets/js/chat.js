/*
 * WhatsApp Web-style chat (CRM).
 * The browser only talks to Laravel (/chat/api/*); Laravel talks to the Evolution API.
 * Depends on the markup in resources/views/chat/index.blade.php and window.WA_I18N.
 */
(function () {
  'use strict';

  var app = document.getElementById('wa-app');
  if (!app) return;

  var T = window.WA_I18N || {};
  var D = app.dataset;
  var LANG = document.documentElement.lang || undefined;
  var CSRF = D.csrf;

  var cfg = {
    instance: parseInt(D.instance, 10),
    pollMessages: Math.max(1000, parseInt(D.pollMessages, 10) || 2500),
    pollChats: Math.max(2000, parseInt(D.pollChats, 10) || 6000),
    maxKb: parseInt(D.maxKb, 10) || 16384,
    urlChats: D.urlChats,
    urlOpen: D.urlOpen,
    urlContacts: D.urlContacts,
    urlSync: D.urlSync,
    urlSyncStatus: D.urlSyncStatus,
    urlInstances: D.urlInstances,
    urlSignature: D.urlSignature,
    signatureSuffix: D.signatureSuffix || '(CRM)',
    chatBase: D.urlChatBase,
    messageBase: D.urlMessageBase,
    urlIndex: D.urlIndex
  };

  var EMOJIS = Array.from('😀😃😄😁😆😅😂🤣🙂😉😊😇🥰😍🤩😘😋😛😜🤪😎🤓🤗🤔🤭🙄😏😌😴😷🤒🥳😢😭😤😡😱😨😓🙏👍👎👏🙌🤝💪👌✌️🤞👋❤️🧡💛💚💙💜🖤💔🔥✨🎉🎁⭐✅❌⚠️📌📎📷💰📞🕐');
  var QUICK = ['👍', '❤️', '😂', '😮', '😢', '🙏'];
  var COLORS = ['#e57373', '#f06292', '#ba68c8', '#7986cb', '#4fc3f7', '#4db6ac', '#81c784', '#ffb74d', '#a1887f', '#90a4ae'];

  // ------------------------------------------------------------------
  // helpers
  // ------------------------------------------------------------------
  function $(id) { return document.getElementById(id); }

  function h(tag, cls, text) {
    var el = document.createElement(tag);
    if (cls) el.className = cls;
    if (text !== undefined && text !== null) el.textContent = text;
    return el;
  }

  function icon(name) { return h('i', 'bi bi-' + name); }

  function show(el, on) { el.classList.toggle('d-none', !on); }

  function safeUrl(url) {
    try {
      var u = new URL(url, window.location.href);
      return (u.protocol === 'http:' || u.protocol === 'https:') ? u.href : null;
    } catch (e) { return null; }
  }

  async function api(url, opts) {
    opts = opts || {};
    var init = {
      method: opts.method || 'GET',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' }
    };
    if (opts.form) {
      init.body = opts.form;
    } else if (opts.body) {
      init.headers['Content-Type'] = 'application/json';
      init.body = JSON.stringify(opts.body);
    }

    var res = await fetch(url, init);
    if (res.status === 401 || res.status === 419) { window.location.reload(); throw new Error('session'); }
    if (res.status === 204) return null;

    var data = null;
    try { data = await res.json(); } catch (e) { /* not json */ }
    if (!res.ok) {
      var err = new Error((data && data.message) || T.error || 'Error');
      err.status = res.status;
      throw err;
    }
    return data;
  }

  var toastTimer = null;
  function toast(msg) {
    var el = $('wa-toast');
    el.textContent = msg;
    show(el, true);
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { show(el, false); }, 3500);
  }

  function debounce(fn, ms) {
    var t;
    return function () {
      var args = arguments, self = this;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(self, args); }, ms);
    };
  }

  function sameDay(a, b) { return a.toDateString() === b.toDateString(); }

  function fmtTime(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleTimeString(LANG, { hour: '2-digit', minute: '2-digit' });
  }

  function fmtDay(iso) {
    var d = new Date(iso), now = new Date(), y = new Date();
    y.setDate(now.getDate() - 1);
    if (sameDay(d, now)) return T.today;
    if (sameDay(d, y)) return T.yesterday;
    return d.toLocaleDateString(LANG, { day: '2-digit', month: 'long', year: 'numeric' });
  }

  function fmtListTime(iso) {
    if (!iso) return '';
    var d = new Date(iso), now = new Date(), y = new Date();
    y.setDate(now.getDate() - 1);
    if (sameDay(d, now)) return fmtTime(iso);
    if (sameDay(d, y)) return T.yesterday;
    if (now - d < 6 * 864e5) return d.toLocaleDateString(LANG, { weekday: 'short' });
    return d.toLocaleDateString(LANG, { day: '2-digit', month: '2-digit', year: '2-digit' });
  }

  function initials(name) {
    var parts = String(name || '?').replace(/^\+/, '').trim().split(/\s+/);
    var s = (parts[0] || '?').charAt(0) + (parts.length > 1 ? parts[1].charAt(0) : '');
    return s.toUpperCase();
  }

  function avatarEl(name, url, extraClass) {
    var el = h('div', 'wa-avatar' + (extraClass ? ' ' + extraClass : ''));
    var hash = 0, s = String(name || '');
    for (var i = 0; i < s.length; i++) hash = (hash * 31 + s.charCodeAt(i)) | 0;
    el.style.backgroundColor = COLORS[Math.abs(hash) % COLORS.length];
    el.textContent = initials(name);

    var safe = url ? safeUrl(url) : null;
    if (safe) {
      var img = document.createElement('img');
      img.alt = '';
      img.referrerPolicy = 'no-referrer';
      img.loading = 'lazy';
      img.onerror = function () { img.remove(); };
      img.src = safe;
      el.appendChild(img);
    }
    return el;
  }

  function ticksEl(status) {
    var cls = 'wa-ticks', ic = 'check2';
    if (status === 'pending') ic = 'clock';
    else if (status === 'delivered') ic = 'check2-all';
    else if (status === 'read' || status === 'played') { ic = 'check2-all'; cls += ' read'; }
    else if (status === 'error') { ic = 'exclamation-circle'; cls += ' error'; }
    var el = h('span', cls);
    el.appendChild(icon(ic));
    return el;
  }

  var URL_RE = /(https?:\/\/[^\s<]+)/g;
  var BOLD_RE = /\*([^*\n]+)\*/g;

  // WhatsApp-style *bold* (the CRM signature header) without touching innerHTML
  function appendText(frag, text) {
    var last = 0, m;
    BOLD_RE.lastIndex = 0;
    while ((m = BOLD_RE.exec(text)) !== null) {
      if (m.index > last) frag.appendChild(document.createTextNode(text.slice(last, m.index)));
      frag.appendChild(h('strong', null, m[1]));
      last = m.index + m[0].length;
    }
    if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
  }

  function linkify(text) {
    var frag = document.createDocumentFragment();
    String(text || '').split(URL_RE).forEach(function (part, i) {
      if (i % 2 === 1) {
        var href = safeUrl(part);
        if (href) {
          var a = h('a', null, part);
          a.href = href; a.target = '_blank'; a.rel = 'noopener noreferrer nofollow';
          frag.appendChild(a);
          return;
        }
      }
      appendText(frag, part);
    });
    return frag;
  }

  function typeLabel(type, deleted) {
    if (deleted || type === 'deleted') return '🚫 ' + T.deleted;
    switch (type) {
      case 'image': return '📷 ' + T.photo;
      case 'video': return '🎥 ' + T.video;
      case 'audio': return '🎤 ' + T.audio;
      case 'document': return '📄 ' + T.document;
      case 'sticker': return '🏷️ ' + T.sticker;
      case 'contact': return '👤 ' + T.contactCard;
      case 'location': return '📍 ' + T.location;
      default: return '';
    }
  }

  // ------------------------------------------------------------------
  // state
  // ------------------------------------------------------------------
  var S = {
    chats: [],
    query: '',
    active: null,          // chat object currently open
    openToken: 0,
    entries: [],           // sorted message entries {key, m, el, contentSig, seq}
    byKey: {},
    hasMore: false,
    serverTime: null,
    reply: null,           // {id, who, text}
    file: null,            // File | Blob pending to send
    fileObjectUrl: null,
    recording: null,       // {recorder, stream, chunks, timer, started, cancelled}
    seq: 0,
    pendingNew: 0,
    loadingChats: false,
    loadingMsgs: false,
    lastRead: 0,
    lastPresence: 0
  };

  var el = {
    list: $('wa-chat-list'), empty: $('wa-chat-empty'), search: $('wa-search'),
    placeholder: $('wa-placeholder'), conv: $('wa-conversation'),
    name: $('wa-conv-name'), sub: $('wa-conv-sub'), avatar: $('wa-conv-avatar'),
    scroller: $('wa-messages'), inner: $('wa-messages-inner'), loadMore: $('wa-load-more'),
    scrollDown: $('wa-scroll-down'), scrollBadge: $('wa-scroll-badge'),
    input: $('wa-input'), send: $('wa-send'), mic: $('wa-mic'),
    replyBar: $('wa-reply-bar'), replyWho: $('wa-reply-who'), replyText: $('wa-reply-text'),
    attachBar: $('wa-attach-bar'), attachPreview: $('wa-attach-preview'), attachName: $('wa-attach-name'),
    file: $('wa-file'), emojiPanel: $('wa-emoji-panel'),
    recording: $('wa-recording'), recTime: $('wa-rec-time'),
    info: $('wa-info'), infoBody: $('wa-info-body'),
    newChat: $('wa-newchat'), newChatQ: $('wa-newchat-q'), newChatResults: $('wa-newchat-results'),
  };

  // ------------------------------------------------------------------
  // chat list
  // ------------------------------------------------------------------
  var CHANNELS = { whatsapp: ['WhatsApp', 'bi-whatsapp'], telegram: ['Telegram', 'bi-telegram'], instagram: ['Instagram', 'bi-instagram'] };
  function channelTag(chat) {
    var c = CHANNELS[chat.channel || 'whatsapp'] || CHANNELS.whatsapp;
    var tag = h('span', 'wa-ch wa-ch-' + (chat.channel || 'whatsapp'));
    tag.title = c[0];
    tag.appendChild(h('i', 'bi ' + c[1]));
    return tag;
  }

  function chatItem(chat) {
    var active = S.active && S.active.id === chat.id;
    var unread = active ? 0 : chat.unread;
    var item = h('div', 'wa-chat-item' + (active ? ' active' : '') + (unread ? ' unread' : ''));
    item.setAttribute('role', 'listitem');
    item.dataset.id = chat.id;
    item.appendChild(avatarEl(chat.name, chat.avatar));

    var body = h('div', 'wa-chat-body');
    var row1 = h('div', 'wa-chat-row');
    var nm = h('div', 'wa-chat-name', chat.name);
    nm.appendChild(channelTag(chat));
    if (chat.in_crm) nm.appendChild(h('span', 'wa-crm-tag', 'CRM'));
    row1.appendChild(nm);
    row1.appendChild(h('div', 'wa-chat-time', fmtListTime(chat.last && chat.last.at)));

    var row2 = h('div', 'wa-chat-row');
    var prev = h('div', 'wa-chat-preview');
    var last = chat.last || {};
    if (last.from_me && last.type !== 'deleted') prev.appendChild(ticksEl(last.status || 'sent'));
    var label = typeLabel(last.type, false);
    var text = last.text || '';
    var line = last.type === 'text' || !label ? text : (text ? label.split(' ')[0] + ' ' + text : label);
    prev.appendChild(h('span', 'txt', line));
    row2.appendChild(prev);
    if (unread) row2.appendChild(h('span', 'wa-badge', unread > 99 ? '99+' : String(unread)));

    body.appendChild(row1);
    body.appendChild(row2);
    item.appendChild(body);
    item.addEventListener('click', function () { openChat(chat); });
    return item;
  }

  function renderChats() {
    var frag = document.createDocumentFragment();
    S.chats.forEach(function (c) { frag.appendChild(chatItem(c)); });
    el.list.replaceChildren(frag);
    show(el.empty, S.chats.length === 0);
  }

  async function loadChats() {
    if (S.loadingChats) return;
    S.loadingChats = true;
    try {
      var url = cfg.urlChats + '?instance=' + cfg.instance + (S.query ? '&q=' + encodeURIComponent(S.query) : '');
      var data = await api(url);
      S.chats = data.chats || [];
      renderChats();

      if (S.active) {
        var fresh = S.chats.find(function (c) { return c.id === S.active.id; });
        if (fresh && fresh.unread > 0 && !document.hidden && nearBottom()) markRead();
      }
    } catch (e) { /* silent: next poll retries */ }
    S.loadingChats = false;
  }

  // ------------------------------------------------------------------
  // conversation
  // ------------------------------------------------------------------
  function nearBottom() {
    var s = el.scroller;
    return s.scrollHeight - s.scrollTop - s.clientHeight < 90;
  }

  // Keeps the floating "scroll down" button just above the reply/attachment bars and the composer
  function positionScrollDown() {
    var below = el.conv.clientHeight - (el.scroller.offsetTop + el.scroller.offsetHeight);
    if (below > 0) el.scrollDown.style.bottom = (below + 14) + 'px';
  }
  if (window.ResizeObserver) new ResizeObserver(positionScrollDown).observe(el.scroller);

  function scrollToBottom() {
    el.scroller.scrollTop = el.scroller.scrollHeight;
    S.pendingNew = 0;
    show(el.scrollDown, false);
    show(el.scrollBadge, false);
  }

  function resetMessages() {
    S.entries = [];
    S.byKey = {};
    S.hasMore = false;
    S.serverTime = null;
    S.pendingNew = 0;
    el.inner.replaceChildren();
    show(el.loadMore, false);
  }

  function setHeader(chat) {
    el.name.textContent = chat.name;
    var ch = CHANNELS[chat.channel || 'whatsapp'] || CHANNELS.whatsapp;
    el.sub.textContent = ch[0] + (chat.phone ? ' · +' + chat.phone : '');
    var av = avatarEl(chat.name, chat.avatar);
    el.avatar.replaceChildren();
    el.avatar.style.backgroundColor = av.style.backgroundColor;
    el.avatar.textContent = av.textContent;
    var img = av.querySelector('img');
    if (img) el.avatar.appendChild(img);
  }

  async function openChat(chat) {
    var token = ++S.openToken;
    stopRecording(true);
    clearAttachment();
    setReply(null);
    el.input.value = '';
    autosize();
    syncButtons();

    S.active = chat;
    resetMessages();
    setHeader(chat);
    show(el.placeholder, false);
    show(el.conv, true);
    app.classList.add('conv-open');
    renderChats();

    try {
      var data = await api(cfg.chatBase + '/' + chat.id + '/messages');
      if (token !== S.openToken) return;
      S.hasMore = !!data.has_more;
      S.serverTime = data.server_time;
      (data.messages || []).forEach(function (m) { upsert(m, false); });
      layout();
      show(el.loadMore, S.hasMore);
      if (data.chat) { S.active = data.chat; setHeader(data.chat); }
      scrollToBottom();
      if (chat.unread > 0) markRead();
      if (!el.info.classList.contains('d-none')) loadInfo();
      el.input.focus();
    } catch (e) {
      if (token === S.openToken) toast(e.message);
    }
  }

  function closeChat() {
    S.openToken++;
    S.active = null;
    stopRecording(true);
    app.classList.remove('conv-open');
    show(el.conv, false);
    show(el.info, false);
    show(el.placeholder, true);
    renderChats();
  }

  async function pollMessages() {
    if (!S.active || S.loadingMsgs || document.hidden || !S.serverTime) return;
    var token = S.openToken;
    S.loadingMsgs = true;
    try {
      var data = await api(cfg.chatBase + '/' + S.active.id + '/messages?since=' + encodeURIComponent(S.serverTime));
      if (token !== S.openToken) { S.loadingMsgs = false; return; }
      S.serverTime = data.server_time;

      var stick = nearBottom();
      var incoming = 0;
      (data.messages || []).forEach(function (m) {
        var isNew = !S.byKey['m' + m.id] && !(m.client_id && S.byKey['t' + m.client_id]);
        upsert(m, true);
        if (isNew && !m.from_me) incoming++;
      });
      layout();

      if (data.chat) { S.active = data.chat; setHeader(data.chat); }
      if (stick) {
        scrollToBottom();
        if (incoming) markRead();
      } else if (incoming) {
        S.pendingNew += incoming;
        el.scrollBadge.textContent = String(S.pendingNew);
        show(el.scrollBadge, true);
        show(el.scrollDown, true);
      }
    } catch (e) { /* silent */ }
    S.loadingMsgs = false;
  }

  async function loadOlder() {
    if (!S.active || !S.hasMore) return;
    var firstServer = S.entries.find(function (e) { return e.m.id; });
    if (!firstServer) return;

    var token = S.openToken;
    var btn = el.loadMore.querySelector('button');
    btn.disabled = true;
    try {
      var data = await api(cfg.chatBase + '/' + S.active.id + '/messages?before_id=' + firstServer.m.id);
      if (token !== S.openToken) return;
      var before = el.scroller.scrollHeight;
      (data.messages || []).forEach(function (m) { upsert(m, false); });
      layout();
      el.scroller.scrollTop += el.scroller.scrollHeight - before;
      S.hasMore = !!data.has_more;
      show(el.loadMore, S.hasMore);
    } catch (e) { toast(e.message); }
    btn.disabled = false;
  }

  async function markRead() {
    if (!S.active) return;
    var now = Date.now();
    if (now - S.lastRead < 1500) return;
    S.lastRead = now;
    var chat = S.active;
    try {
      await api(cfg.chatBase + '/' + chat.id + '/read', { method: 'POST' });
      chat.unread = 0;
      var inList = S.chats.find(function (c) { return c.id === chat.id; });
      if (inList) inList.unread = 0;
      renderChats();
    } catch (e) { /* silent */ }
  }

  // ------------------------------------------------------------------
  // message rendering (incremental: never rebuilds untouched bubbles,
  // so audio/video that is playing is not interrupted)
  // ------------------------------------------------------------------
  function contentSig(m) {
    return [m.body, m.deleted ? 1 : 0, (m.reactions || []).join(','), m.media_url, m.error, m.type].join('|');
  }

  function cmp(a, b) {
    var aSrv = !!a.m.id, bSrv = !!b.m.id;
    if (aSrv && bSrv) return a.m.id - b.m.id;
    if (aSrv) return -1;
    if (bSrv) return 1;
    return a.seq - b.seq;
  }

  function upsert(m, allowReplaceTemp) {
    var key = m.id ? 'm' + m.id : 't' + m.client_id;
    var entry = S.byKey[key];

    // Server copy of a message that exists as an optimistic temp row
    if (!entry && m.id && m.client_id && S.byKey['t' + m.client_id]) {
      var temp = S.byKey['t' + m.client_id];
      delete S.byKey['t' + m.client_id];
      temp.key = key;
      temp.m = m;
      S.byKey[key] = temp;
      S.entries.sort(cmp);
      var rebuilt = buildItem(temp);
      temp.el.replaceWith(rebuilt);
      temp.el = rebuilt;
      temp.contentSig = contentSig(m);
      reorderDom();
      return;
    }

    if (entry) {
      var oldSig = entry.contentSig;
      var oldStatus = entry.m.status;
      entry.m = m;
      var sig = contentSig(m);
      if (sig !== oldSig) {
        var fresh = buildItem(entry);
        entry.el.replaceWith(fresh);
        entry.el = fresh;
        entry.contentSig = sig;
      } else if (m.status !== oldStatus) {
        var old = entry.el.querySelector('.wa-meta .wa-ticks');
        if (old) old.replaceWith(ticksEl(m.status));
        var err = entry.el.querySelector('.wa-error-line');
        if (err && m.status !== 'error') err.remove();
      }
      return;
    }

    entry = { key: key, m: m, seq: ++S.seq, contentSig: contentSig(m), el: null };
    entry.el = buildItem(entry);
    S.byKey[key] = entry;
    S.entries.push(entry);
    S.entries.sort(cmp);

    var idx = S.entries.indexOf(entry);
    var next = S.entries[idx + 1];
    el.inner.insertBefore(entry.el, next ? next.el : null);
  }

  function reorderDom() {
    var frag = document.createDocumentFragment();
    S.entries.forEach(function (e) { frag.appendChild(e.el); });
    el.inner.appendChild(frag);
  }

  function removeEntry(key) {
    var e = S.byKey[key];
    if (!e) return;
    e.el.remove();
    delete S.byKey[key];
    S.entries = S.entries.filter(function (x) { return x !== e; });
    layout();
  }

  // day separators + first-of-group tails
  function layout() {
    var prev = null;
    S.entries.forEach(function (e) {
      var d = new Date(e.m.ts || Date.now());
      var newDay = !prev || !sameDay(new Date(prev.m.ts || Date.now()), d);
      var dayEl = e.el.querySelector('.wa-day');
      dayEl.classList.toggle('d-none', !newDay);
      if (newDay) dayEl.firstChild.textContent = fmtDay(e.m.ts || new Date().toISOString());
      var row = e.el.querySelector('.wa-row');
      row.classList.toggle('first', newDay || !prev || prev.m.from_me !== e.m.from_me);
      prev = e;
    });
  }

  function buildItem(entry) {
    var m = entry.m;
    var item = h('div', 'wa-item');
    item.dataset.key = entry.key;

    var day = h('div', 'wa-day d-none');
    day.appendChild(h('span'));
    item.appendChild(day);

    var row = h('div', 'wa-row ' + (m.from_me ? 'out' : 'in'));
    var reactions = (m.reactions || []).filter(Boolean);
    var bubble = h('div', 'wa-bubble' + (reactions.length ? ' reacted' : ''));

    if (m.id && !m.deleted) {
      var actions = h('div', 'wa-actions');
      var btn = h('button', null);
      btn.type = 'button';
      btn.appendChild(icon('chevron-down'));
      btn.addEventListener('click', function (ev) { ev.stopPropagation(); openMenu(bubble, entry); });
      actions.appendChild(btn);
      bubble.appendChild(actions);
    }

    if (m.quoted && !m.deleted) {
      var q = h('div', 'wa-quote' + (m.quoted.from_me === false ? ' them' : ''));
      q.appendChild(h('div', 'wa-quote-who', m.quoted.from_me ? T.you : (S.active ? S.active.name : '')));
      q.appendChild(h('div', 'wa-quote-text', m.quoted.preview || ''));
      bubble.appendChild(q);
    }

    bubble.appendChild(buildContent(m));

    var meta = h('span', 'wa-meta');
    meta.appendChild(h('span', null, fmtTime(m.ts)));
    if (m.from_me) meta.appendChild(ticksEl(m.status));
    bubble.appendChild(meta);

    if (m.from_me && m.status === 'error') {
      var errLine = h('div', 'wa-error-line', T.retry);
      errLine.title = m.error || '';
      errLine.addEventListener('click', function () { retry(entry); });
      bubble.appendChild(errLine);
    }

    if (reactions.length) {
      var r = h('div', 'wa-reactions');
      reactions.forEach(function (emoji) { r.appendChild(h('span', null, emoji)); });
      bubble.appendChild(r);
    }

    row.appendChild(bubble);
    item.appendChild(row);
    return item;
  }

  function buildContent(m) {
    var wrap = h('div', 'wa-content');
    var url = m.media_url || m.local_url || null;

    if (m.deleted) {
      var del = h('div', 'wa-deleted');
      del.appendChild(icon('slash-circle'));
      del.appendChild(document.createTextNode(' ' + T.deleted));
      wrap.appendChild(del);
      return wrap;
    }

    switch (m.type) {
      case 'image':
        if (url) {
          var img = h('img', 'wa-media-img');
          img.loading = 'lazy'; img.alt = '';
          img.src = url;
          img.addEventListener('click', function () { lightbox(url); });
          wrap.appendChild(img);
        }
        break;
      case 'sticker':
        if (url) { var st = h('img', 'wa-media-sticker'); st.loading = 'lazy'; st.alt = ''; st.src = url; wrap.appendChild(st); }
        break;
      case 'video':
        if (url) { var v = h('video', 'wa-media-video'); v.controls = true; v.preload = 'metadata'; v.src = url; wrap.appendChild(v); }
        break;
      case 'audio':
        if (url) { var a = h('audio', 'wa-audio'); a.controls = true; a.preload = 'none'; a.src = url; wrap.appendChild(a); }
        break;
      case 'document':
        var doc = h('a', 'wa-doc');
        if (url) { doc.href = url; doc.target = '_blank'; doc.rel = 'noopener'; }
        doc.appendChild(icon('file-earmark-text'));
        var info = h('div');
        info.appendChild(h('div', 'wa-doc-name', m.file_name || T.document));
        info.appendChild(h('div', 'wa-doc-sub', (m.mimetype || '').split('/').pop().toUpperCase()));
        doc.appendChild(info);
        wrap.appendChild(doc);
        break;
      case 'contact':
        wrap.appendChild(h('div', 'wa-text', '👤 ' + (m.body || T.contactCard)));
        return wrap;
      case 'location':
        var loc = h('div', 'wa-text');
        loc.appendChild(document.createTextNode('📍 '));
        loc.appendChild(linkify(m.body || T.location));
        wrap.appendChild(loc);
        return wrap;
      case 'unsupported':
        wrap.appendChild(h('div', 'wa-unsupported', T.unsupported));
        return wrap;
      default:
        var tx = h('div', 'wa-text');
        tx.appendChild(linkify(m.body || ''));
        wrap.appendChild(tx);
        return wrap;
    }

    if (m.body) {
      var cap = h('div', 'wa-caption');
      cap.appendChild(linkify(m.body));
      wrap.appendChild(cap);
    }
    return wrap;
  }

  // ------------------------------------------------------------------
  // message actions menu
  // ------------------------------------------------------------------
  var openMenuEl = null;
  function closeMenu() {
    if (openMenuEl) { openMenuEl.remove(); openMenuEl = null; }
  }

  function openMenu(bubble, entry) {
    closeMenu();
    var m = entry.m;
    var menu = h('div', 'wa-menu');
    menu.style.top = '26px';
    if (m.from_me) menu.style.right = '4px'; else menu.style.left = '4px';

    var quick = h('div', 'wa-quick');
    QUICK.forEach(function (emoji) {
      var b = h('button', null, emoji);
      b.type = 'button';
      b.title = T.react;
      b.addEventListener('click', function (ev) { ev.stopPropagation(); closeMenu(); react(m, emoji); });
      quick.appendChild(b);
    });
    menu.appendChild(quick);

    function item(ic, label, fn) {
      var b = h('button');
      b.type = 'button';
      b.appendChild(icon(ic));
      b.appendChild(document.createTextNode(label));
      b.addEventListener('click', function (ev) { ev.stopPropagation(); closeMenu(); fn(); });
      menu.appendChild(b);
    }

    item('reply', T.reply, function () {
      var preview = m.body || typeLabel(m.type, false);
      setReply({ id: m.id, who: m.from_me ? T.you : (S.active ? S.active.name : ''), text: preview });
      el.input.focus();
    });
    if (m.body) {
      item('clipboard', T.copy, function () {
        if (navigator.clipboard) navigator.clipboard.writeText(m.body).then(function () { toast(T.copied); });
      });
    }
    if (m.media_url) {
      item('download', T.download, function () { window.open(m.media_url, '_blank', 'noopener'); });
    }
    if (m.from_me && m.message_id) {
      item('trash', T.remove, function () { removeMessage(m); });
    }

    bubble.appendChild(menu);
    openMenuEl = menu;
  }

  async function react(m, emoji) {
    try {
      var data = await api(cfg.messageBase + '/' + m.id + '/react', { method: 'POST', body: { emoji: emoji } });
      if (data && data.message) { upsert(data.message, true); layout(); }
    } catch (e) { toast(e.message); }
  }

  async function removeMessage(m) {
    if (!window.confirm(T.confirmDelete)) return;
    try {
      var data = await api(cfg.messageBase + '/' + m.id, { method: 'DELETE' });
      if (data && data.message) { upsert(data.message, true); layout(); }
      loadChats();
    } catch (e) { toast(e.message); }
  }

  async function retry(entry) {
    if (!entry.m.id) return;
    try {
      var data = await api(cfg.messageBase + '/' + entry.m.id + '/retry', { method: 'POST' });
      if (data && data.message) { upsert(data.message, true); layout(); }
    } catch (e) { toast(e.message); }
  }

  function lightbox(url) {
    var box = h('div', 'wa-lightbox');
    var img = document.createElement('img');
    img.src = url; img.alt = '';
    box.appendChild(img);
    box.addEventListener('click', function () { box.remove(); });
    document.body.appendChild(box);
  }

  // ------------------------------------------------------------------
  // composer
  // ------------------------------------------------------------------
  function autosize() {
    el.input.style.height = 'auto';
    el.input.style.height = Math.min(el.input.scrollHeight, 140) + 'px';
  }

  function syncButtons() {
    var has = el.input.value.trim() !== '' || !!S.file || !!S.recording;
    show(el.send, has);
    show(el.mic, !has);
  }

  function setReply(r) {
    S.reply = r;
    if (r) {
      el.replyWho.textContent = r.who;
      el.replyText.textContent = r.text;
    }
    show(el.replyBar, !!r);
  }

  function clearAttachment() {
    if (S.fileObjectUrl) URL.revokeObjectURL(S.fileObjectUrl);
    S.file = null;
    S.fileObjectUrl = null;
    el.file.value = '';
    show(el.attachBar, false);
    syncButtons();
  }

  function setAttachment(file) {
    if (file.size > cfg.maxKb * 1024) { toast(T.fileTooBig); el.file.value = ''; return; }
    clearAttachment();
    S.file = file;
    el.attachName.textContent = file.name || '';
    el.attachPreview.style.backgroundImage = '';
    el.attachPreview.replaceChildren();
    if (file.type && file.type.indexOf('image/') === 0) {
      S.fileObjectUrl = URL.createObjectURL(file);
      el.attachPreview.style.backgroundImage = 'url("' + S.fileObjectUrl + '")';
    } else {
      el.attachPreview.appendChild(icon(file.type && file.type.indexOf('video/') === 0 ? 'film' : 'file-earmark-text'));
    }
    show(el.attachBar, true);
    syncButtons();
    el.input.focus();
  }

  function guessType(mime, asVoice) {
    mime = mime || '';
    if (asVoice || mime.indexOf('audio/') === 0) return 'audio';
    if (mime.indexOf('image/') === 0) return 'image';
    if (mime.indexOf('video/') === 0 && mime !== 'video/webm') return 'video';
    return 'document';
  }

  async function sendMessage(opts) {
    if (!S.active) return;
    var chat = S.active;
    var text = (opts.text || '').trim();
    var file = opts.file || null;
    if (!text && !file) return;

    var clientId = 'c' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
    var reply = S.reply;
    var tempType = file ? guessType(file.type, opts.asVoice) : 'text';
    var localUrl = file && tempType !== 'document' ? URL.createObjectURL(file) : null;

    var temp = {
      id: null, client_id: clientId, from_me: true, type: tempType,
      body: text || null, mimetype: file ? file.type : null, file_name: file ? (file.name || '') : null,
      local_url: localUrl, media_url: null,
      quoted: reply ? { preview: reply.text, from_me: reply.who === T.you } : null,
      reactions: [], status: 'pending', error: null, deleted: false, ts: new Date().toISOString()
    };

    upsert(temp, false);
    layout();
    scrollToBottom();

    var form = new FormData();
    if (text) form.append('text', text);
    form.append('client_id', clientId);
    if (reply && reply.id) form.append('quoted_id', reply.id);
    if (file) {
      form.append('file', file, file.name || (opts.asVoice ? 'voice.' + (file.type.indexOf('ogg') > -1 ? 'ogg' : 'webm') : 'file'));
      if (opts.asVoice) form.append('as_voice', '1');
    }

    setReply(null);

    try {
      var data = await api(cfg.chatBase + '/' + chat.id + '/send', { method: 'POST', form: form });
      if (S.active && S.active.id === chat.id) {
        upsert(data.message, true);
        layout();
      }
      loadChats();
    } catch (e) {
      removeEntry('t' + clientId);
      toast(e.message);
      if (!file && S.active && S.active.id === chat.id && !el.input.value) {
        el.input.value = text; autosize(); syncButtons();
      }
    } finally {
      if (localUrl) setTimeout(function () { URL.revokeObjectURL(localUrl); }, 60000);
    }
  }

  function submit() {
    if (S.recording) { stopRecording(false); return; }
    var text = el.input.value;
    var file = S.file;
    if (!text.trim() && !file) return;

    el.input.value = '';
    autosize();
    S.file = null;
    clearAttachmentUi();
    syncButtons();
    sendMessage({ text: text, file: file });
  }

  function clearAttachmentUi() {
    // keeps the object URL alive for the optimistic bubble; only resets the bar
    if (S.fileObjectUrl) { var u = S.fileObjectUrl; setTimeout(function () { URL.revokeObjectURL(u); }, 60000); }
    S.fileObjectUrl = null;
    el.file.value = '';
    show(el.attachBar, false);
  }

  // ------------------------------------------------------------------
  // voice recording
  // ------------------------------------------------------------------
  function fmtDuration(sec) {
    return Math.floor(sec / 60) + ':' + String(sec % 60).padStart(2, '0');
  }

  async function startRecording() {
    if (!S.active) return;
    if (!navigator.mediaDevices || !window.MediaRecorder) { toast(T.micUnsupported); return; }

    var stream;
    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch (e) { toast(T.micDenied); return; }

    var mime = ['audio/ogg;codecs=opus', 'audio/webm;codecs=opus', 'audio/webm', 'audio/mp4']
      .find(function (t) { return MediaRecorder.isTypeSupported(t); });
    var recorder = mime ? new MediaRecorder(stream, { mimeType: mime }) : new MediaRecorder(stream);
    var rec = { recorder: recorder, stream: stream, chunks: [], cancelled: false, started: Date.now(), timer: null };

    recorder.ondataavailable = function (ev) { if (ev.data && ev.data.size) rec.chunks.push(ev.data); };
    recorder.onstop = function () {
      stream.getTracks().forEach(function (t) { t.stop(); });
      clearInterval(rec.timer);
      if (rec.cancelled || !rec.chunks.length) return;
      var type = (recorder.mimeType || mime || 'audio/webm').split(';')[0];
      var blob = new Blob(rec.chunks, { type: type });
      blob.name = 'voice.' + (type.indexOf('ogg') > -1 ? 'ogg' : type.indexOf('mp4') > -1 ? 'm4a' : 'webm');
      sendMessage({ file: blob, asVoice: true });
    };

    S.recording = rec;
    el.recTime.textContent = '0:00';
    rec.timer = setInterval(function () {
      el.recTime.textContent = fmtDuration(Math.floor((Date.now() - rec.started) / 1000));
    }, 500);
    show(el.recording, true);
    syncButtons();
    recorder.start();
  }

  function stopRecording(cancel) {
    var rec = S.recording;
    if (!rec) return;
    rec.cancelled = !!cancel;
    S.recording = null;
    show(el.recording, false);
    try { rec.recorder.stop(); } catch (e) { /* already stopped */ }
    syncButtons();
  }

  // ------------------------------------------------------------------
  // new chat
  // ------------------------------------------------------------------
  function newChatItem(title, sub, phone) {
    var item = h('div', 'wa-newchat-item');
    item.appendChild(avatarEl(title, null, 'wa-avatar-sm'));
    var box = h('div');
    box.appendChild(document.createTextNode(title));
    if (sub) box.appendChild(h('small', null, sub));
    item.appendChild(box);
    item.addEventListener('click', function () { startChat(phone); });
    return item;
  }

  var searchContacts = debounce(async function () {
    var q = el.newChatQ.value.trim();
    var digits = q.replace(/\D/g, '');
    var frag = document.createDocumentFragment();

    if (digits.length >= 8) frag.appendChild(newChatItem(T.startChat + ' +' + digits, null, digits));

    if (q) {
      try {
        var data = await api(cfg.urlContacts + '?q=' + encodeURIComponent(q));
        (data.contacts || []).forEach(function (c) {
          frag.appendChild(newChatItem(c.name || c.phone, c.phone, String(c.phone).replace(/\D/g, '')));
        });
      } catch (e) { /* ignore */ }
    }

    if (!frag.childNodes.length && q) frag.appendChild(h('div', 'wa-newchat-hint', T.noResults));
    el.newChatResults.replaceChildren(frag);
  }, 250);

  async function startChat(phone) {
    try {
      var data = await api(cfg.urlOpen, { method: 'POST', body: { instance_id: cfg.instance, phone: phone } });
      show(el.newChat, false);
      el.newChatQ.value = '';
      el.newChatResults.replaceChildren();
      await loadChats();
      openChat(data.chat);
    } catch (e) { toast(e.message); }
  }

  // ------------------------------------------------------------------
  // CRM side panel
  // ------------------------------------------------------------------
  async function loadInfo() {
    if (!S.active) return;
    var chat = S.active;
    el.infoBody.replaceChildren(h('div', 'wa-info-card', '…'));
    try {
      var data = await api(cfg.chatBase + '/' + chat.id + '/contact');
      if (!S.active || S.active.id !== chat.id) return;
      renderInfo(chat, data);
    } catch (e) { toast(e.message); }
  }

  function infoRow(label, value) {
    var r = h('div', 'wa-info-row');
    r.appendChild(h('span', null, label));
    r.appendChild(h('span', null, value === null || value === undefined || value === '' ? '—' : String(value)));
    return r;
  }

  function renderInfo(chat, d) {
    var body = el.infoBody;
    body.replaceChildren();

    var top = h('div', 'wa-info-top');
    var title = d.contact ? d.contact.name : (d.push_name || chat.name);
    top.appendChild(avatarEl(title, chat.avatar));
    top.appendChild(h('div', 'wa-info-name', title));
    top.appendChild(h('div', 'wa-info-phone', d.phone ? '+' + d.phone : ''));
    body.appendChild(top);

    if (chat.unidentified) {
      var lk = h('div', 'wa-info-card');
      lk.appendChild(h('h6', null, T.linkTitle));
      lk.appendChild(h('p', 'small text-muted', T.linkHelp));
      var phoneIn = h('input', 'form-control'); phoneIn.placeholder = T.linkPlaceholder; phoneIn.inputMode = 'tel'; phoneIn.maxLength = 30;
      lk.appendChild(phoneIn);
      var lkBtn = h('button', 'btn btn-sm btn-success mt-2', T.linkButton);
      lkBtn.type = 'button';
      lkBtn.addEventListener('click', async function () {
        lkBtn.disabled = true;
        try {
          var r = await api(cfg.chatBase + '/' + chat.id + '/link-phone', { method: 'POST', body: { phone: phoneIn.value } });
          toast(T.linkDone);
          S.active = r.chat || null;
          await loadChats();
          if (r.chat) { openChat(S.chats.find(function (c) { return c.id === r.chat.id; }) || r.chat); } else { closeChat(); }
        } catch (e) { toast(e.message); lkBtn.disabled = false; }
      });
      lk.appendChild(lkBtn);
      body.appendChild(lk);
    }

    var crm = h('div', 'wa-info-card');
    crm.appendChild(h('h6', null, 'CRM'));

    if (d.contact) {
      var c = d.contact;
      crm.appendChild(h('span', 'wa-pill ok', T.inCrm));
      crm.appendChild(infoRow(T.name, c.name));
      crm.appendChild(infoRow(T.phone, c.phone));
      crm.appendChild(infoRow(T.email, c.email));
      crm.appendChild(infoRow(T.status, c.status));
      crm.appendChild(infoRow(T.score, c.score));
      if (c.ignore_me) {
        var optOut = h('div', 'mt-2');
        optOut.appendChild(h('span', 'wa-pill warn', T.optedOut));
        crm.appendChild(optOut);
      }

      var links = h('div', 'mt-3 d-flex gap-2');
      [[c.url, T.openContact], [c.edit_url, T.editContact]].forEach(function (l) {
        var a = h('a', 'btn btn-sm btn-outline-secondary', l[1]);
        a.href = l[0];
        links.appendChild(a);
      });
      crm.appendChild(links);

      // inline edit
      var form = h('div', 'mt-3');
      form.appendChild(h('label', null, T.name));
      var nameIn = h('input', 'form-control'); nameIn.value = c.name || ''; nameIn.maxLength = 255;
      form.appendChild(nameIn);
      form.appendChild(h('label', null, T.email));
      var mailIn = h('input', 'form-control'); mailIn.type = 'email'; mailIn.value = c.email || ''; mailIn.maxLength = 255;
      form.appendChild(mailIn);
      form.appendChild(saveButton(chat, nameIn, mailIn));
      crm.appendChild(form);
    } else {
      crm.appendChild(h('span', 'wa-pill muted', T.notInCrm));
      var f = h('div', 'mt-3');
      f.appendChild(h('label', null, T.name));
      var n = h('input', 'form-control'); n.value = d.push_name || ''; n.maxLength = 255;
      f.appendChild(n);
      f.appendChild(h('label', null, T.email));
      var em = h('input', 'form-control'); em.type = 'email'; em.maxLength = 255;
      f.appendChild(em);
      f.appendChild(saveButton(chat, n, em, true));
      crm.appendChild(f);
    }
    body.appendChild(crm);

    var ai = h('div', 'wa-info-card');
    ai.appendChild(h('h6', null, T.aiAuto));
    if (!d.ai.enabled) {
      ai.appendChild(h('span', 'wa-pill muted', T.aiOff));
    } else {
      ai.appendChild(h('span', 'wa-pill ' + (d.ai.paused ? 'warn' : 'ok'), d.ai.paused ? T.aiPaused : T.aiActive));
      if (d.contact) {
        var tgl = h('button', 'btn btn-sm btn-outline-secondary mt-3 d-block', d.ai.paused ? T.resumeAi : T.pauseAi);
        tgl.type = 'button';
        tgl.addEventListener('click', async function () {
          tgl.disabled = true;
          try {
            await api(cfg.chatBase + '/' + chat.id + '/ai-pause', { method: 'POST', body: { paused: !d.ai.paused } });
            loadInfo();
          } catch (e) { toast(e.message); tgl.disabled = false; }
        });
        ai.appendChild(tgl);
      }
    }
    body.appendChild(ai);
  }

  function saveButton(chat, nameIn, mailIn, creating) {
    var b = h('button', 'btn btn-sm btn-success', creating ? T.saveToCrm : T.save);
    b.type = 'button';
    b.addEventListener('click', async function () {
      if (!nameIn.value.trim()) { nameIn.focus(); return; }
      b.disabled = true;
      try {
        await api(cfg.chatBase + '/' + chat.id + '/contact', {
          method: 'POST', body: { name: nameIn.value.trim(), email: mailIn.value.trim() || null }
        });
        loadInfo();
        loadChats();
      } catch (e) { toast(e.message); b.disabled = false; }
    });
    return b;
  }

  // ------------------------------------------------------------------
  // events
  // ------------------------------------------------------------------
  el.search.addEventListener('input', debounce(function () {
    S.query = el.search.value.trim();
    loadChats();
  }, 250));

  $('wa-new').addEventListener('click', function () {
    var open = el.newChat.classList.contains('d-none');
    show(el.newChat, open);
    if (open) el.newChatQ.focus();
  });
  el.newChatQ.addEventListener('input', searchContacts);
  el.newChatQ.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter') {
      var digits = el.newChatQ.value.replace(/\D/g, '');
      if (digits.length >= 8) startChat(digits);
    }
  });

  // ---- history import: visible progress, completion and failure -----------------------------------
  var syncTimer = null;

  function fmt(tpl, vars) {
    return String(tpl || '').replace(/:(\w+)/g, function (m, k) { return vars && vars[k] !== undefined ? vars[k] : m; });
  }

  function renderSync(st) {
    var bar = $('wa-sync-bar'), btn = $('wa-sync');
    var busy = st.state === 'queued' || st.state === 'running';
    btn.classList.toggle('wa-spin', busy);
    btn.disabled = busy;

    if (st.state === 'idle') { bar.classList.add('d-none'); return; }

    var text, cls = 'info';
    if (st.state === 'queued') {
      text = st.waiting_seconds > 12 ? T.syncWorkerHint : T.syncWaiting;
      if (st.waiting_seconds > 12) cls = 'warn';
    } else if (st.state === 'running') {
      text = st.chats_total ? T.syncRunning + ' ' + fmt(T.syncProgress, { done: st.chats_done, total: st.chats_total, messages: st.messages }) : T.syncRunning;
    } else if (st.state === 'done') {
      text = fmt(T.syncDone, { chats: st.chats_done, messages: st.messages }); cls = 'ok';
      if (!st.messages) { text = T.syncEmpty; cls = 'warn'; }
    } else {
      text = st.error === 'no_progress' ? T.syncStalled : (T.syncFailed + (st.error ? ': ' + st.error : '')); cls = 'err';
    }
    bar.className = 'wa-sync-bar ' + cls;
    bar.textContent = text;
  }

  async function pollSync(first) {
    clearTimeout(syncTimer);
    var st;
    try { st = await api(cfg.urlInstances + '/' + (S.syncInstance || cfg.instance) + '/sync'); } catch (e) { return; }
    var wasBusy = S.syncBusy;
    S.syncBusy = st.state === 'queued' || st.state === 'running';
    renderSync(st);

    if (S.syncBusy) {
      loadChats();                                        // conversations appear while they are imported
      syncTimer = setTimeout(pollSync, 2000);
    } else if (wasBusy) {
      loadChats();
      toast(st.state === 'done' ? (st.messages ? fmt(T.syncDone, { chats: st.chats_done, messages: st.messages }) : T.syncEmpty) : (T.syncFailed + (st.error && st.error !== 'no_progress' ? ': ' + st.error : '')));
      if (st.state === 'done' && st.messages) setTimeout(function () { $('wa-sync-bar').classList.add('d-none'); }, 8000);
    } else if (first && st.state === 'done') {
      setTimeout(function () { $('wa-sync-bar').classList.add('d-none'); }, 4000);
    }
  }

  // ---- signature: "Name (CRM):" in front of what customers receive -----------------------------------
  var signMenu = $('wa-sign-menu'), signInput = $('wa-sign-input'), signBtn = $('wa-sign');

  function cleanSignature(v) { return String(v || '').replace(/[*_~`\u0000-\u001f]+/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 40); }
  function signHeader(name) { return name ? '*' + name + ' ' + cfg.signatureSuffix + ':*' : ''; }

  function paintSignature(name) {
    cfg.signature = name;
    signBtn.classList.toggle('wa-on', !!name);
    signBtn.title = name ? fmt(T.signOn, { name: name + ' ' + cfg.signatureSuffix }) : T.signOff;
  }

  function previewSignature() {
    var name = cleanSignature(signInput.value);
    $('wa-sign-preview').textContent = name ? T.signPreview + ' ' + signHeader(name).replace(/\*/g, '') : T.signOff;
  }

  signBtn.addEventListener('click', function (ev) {
    ev.stopPropagation();
    syncMenu.classList.add('d-none');
    signMenu.classList.toggle('d-none');
    if (!signMenu.classList.contains('d-none')) { previewSignature(); signInput.focus(); }
  });
  signMenu.addEventListener('click', function (ev) { ev.stopPropagation(); });
  signInput.addEventListener('input', previewSignature);
  signInput.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') $('wa-sign-save').click(); });
  $('wa-sign-save').addEventListener('click', async function () {
    try {
      var r = await api(cfg.urlSignature, { method: 'PUT', body: { name: signInput.value } });
      signInput.value = r.name || '';
      paintSignature(r.name || '');
      signMenu.classList.add('d-none');
      toast(T.signSaved);
    } catch (e) { toast(e.message); }
  });
  document.addEventListener('click', function () { signMenu.classList.add('d-none'); });
  cfg.signature = D.signature || '';
  paintSignature(cfg.signature);

  // the sync button opens a menu: any of the user's numbers can be imported, not only the one on screen
  var syncMenu = $('wa-sync-menu');

  async function startSync(instanceId) {
    syncMenu.classList.add('d-none');
    S.syncInstance = instanceId;
    try {
      var st = await api(cfg.urlInstances + '/' + instanceId + '/sync', { method: 'POST' });
      S.syncBusy = true;
      renderSync(st);
      pollSync();
    } catch (e) { toast(e.message); }
  }

  $('wa-sync').addEventListener('click', function (ev) {
    ev.stopPropagation();
    var items = syncMenu.querySelectorAll('.wa-sync-item');
    if (items.length <= 1) { startSync(cfg.instance); return; }
    syncMenu.classList.toggle('d-none');
  });
  syncMenu.addEventListener('click', function (ev) {
    var b = ev.target.closest('.wa-sync-item');
    if (b) startSync(parseInt(b.getAttribute('data-instance'), 10));
  });
  document.addEventListener('click', function () { syncMenu.classList.add('d-none'); });

  pollSync(true);   // resumes the display when the page is opened while an import is running

  var instanceSelect = $('wa-instance-select');
  if (instanceSelect) {
    instanceSelect.addEventListener('change', function () {
      window.location.href = cfg.urlIndex + '?instance=' + encodeURIComponent(instanceSelect.value);
    });
  }

  $('wa-back').addEventListener('click', closeChat);
  $('wa-info-btn').addEventListener('click', function () {
    var open = el.info.classList.contains('d-none');
    show(el.info, open);
    if (open) loadInfo();
  });
  $('wa-conv-title-box').addEventListener('click', function () { show(el.info, true); loadInfo(); });
  $('wa-info-close').addEventListener('click', function () { show(el.info, false); });

  el.loadMore.querySelector('button').addEventListener('click', loadOlder);
  el.scrollDown.addEventListener('click', scrollToBottom);
  el.scroller.addEventListener('scroll', function () {
    if (nearBottom()) {
      S.pendingNew = 0;
      show(el.scrollDown, false);
      show(el.scrollBadge, false);
    } else if (el.scroller.scrollHeight - el.scroller.scrollTop - el.scroller.clientHeight > 300) {
      show(el.scrollDown, true);
    }
  });

  el.input.addEventListener('input', function () {
    autosize();
    syncButtons();
    var now = Date.now();
    if (S.active && el.input.value && now - S.lastPresence > 3500) {
      S.lastPresence = now;
      api(cfg.chatBase + '/' + S.active.id + '/presence', { method: 'POST' }).catch(function () { /* cosmetic */ });
    }
  });
  el.input.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing) {
      ev.preventDefault();
      submit();
    }
  });
  el.input.addEventListener('paste', function (ev) {
    var items = (ev.clipboardData && ev.clipboardData.files) || [];
    if (items.length) { ev.preventDefault(); setAttachment(items[0]); }
  });

  el.send.addEventListener('click', submit);
  el.mic.addEventListener('click', startRecording);
  $('wa-rec-cancel').addEventListener('click', function () { stopRecording(true); });
  $('wa-attach-btn').addEventListener('click', function () { el.file.click(); });
  el.file.addEventListener('change', function () { if (el.file.files[0]) setAttachment(el.file.files[0]); });
  $('wa-attach-cancel').addEventListener('click', clearAttachment);
  $('wa-reply-cancel').addEventListener('click', function () { setReply(null); });

  // emoji panel
  EMOJIS.forEach(function (emoji) {
    var b = h('button', null, emoji);
    b.type = 'button';
    b.addEventListener('click', function () {
      var i = el.input, start = i.selectionStart || i.value.length;
      i.value = i.value.slice(0, start) + emoji + i.value.slice(i.selectionEnd || start);
      i.focus();
      i.setSelectionRange(start + emoji.length, start + emoji.length);
      autosize(); syncButtons();
    });
    el.emojiPanel.appendChild(b);
  });
  $('wa-emoji-btn').addEventListener('click', function (ev) {
    ev.stopPropagation();
    show(el.emojiPanel, el.emojiPanel.classList.contains('d-none'));
  });

  // drag & drop a file onto the conversation
  el.conv.addEventListener('dragover', function (ev) { ev.preventDefault(); });
  el.conv.addEventListener('drop', function (ev) {
    ev.preventDefault();
    if (ev.dataTransfer.files && ev.dataTransfer.files[0]) setAttachment(ev.dataTransfer.files[0]);
  });

  document.addEventListener('click', function (ev) {
    closeMenu();
    if (!ev.target.closest('.wa-emoji-wrap')) show(el.emojiPanel, false);
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') {
      closeMenu(); show(el.emojiPanel, false);
      var lb = document.querySelector('.wa-lightbox'); if (lb) lb.remove();
    }
  });
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { loadChats(); pollMessages(); }
  });

  // ------------------------------------------------------------------
  // boot
  // ------------------------------------------------------------------
  loadChats().then(function () {
    var wanted = parseInt(new URLSearchParams(window.location.search).get('chat'), 10);
    if (wanted) {
      var c = S.chats.find(function (x) { return x.id === wanted; });
      if (c) openChat(c);
    }
  });
  setInterval(function () { if (!document.hidden) loadChats(); }, cfg.pollChats);
  setInterval(pollMessages, cfg.pollMessages);
})();
