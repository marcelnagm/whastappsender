/*! SAMA Envios website chat. Usage: <script src="https://YOUR-DOMAIN/w/KEY.js" async></script> */
(function () {
  var API = '__API__', KEY = '__KEY__';
  if (window['__sama_wc_' + KEY]) return; window['__sama_wc_' + KEY] = true;
  var store = { get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }, set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) {} } };
  function rid(n) { var a = new Uint8Array(n / 2); (window.crypto || window.msCrypto).getRandomValues(a); return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
  var visitor = store.get('sama_wc_v_' + KEY); if (!/^[a-f0-9]{32}$/.test(visitor || '')) { visitor = rid(32); store.set('sama_wc_v_' + KEY, visitor); }
  function el(t, a, c) { var e = document.createElement(t); for (var k in (a || {})) e.setAttribute(k, a[k]); (c || []).forEach(function (x) { e.appendChild(typeof x === 'string' ? document.createTextNode(x) : x); }); return e; }

  fetch(API, { headers: { Accept: 'application/json' } }).then(function (r) { if (!r.ok) throw 0; return r.json(); }).then(draw).catch(function () {});

  function draw(d) {
    var host = el('div'); document.body.appendChild(host);
    var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host, c = d.color || '#25d366', T = d.texts;
    root.appendChild(el('style', {}, ['*{box-sizing:border-box}.w{position:fixed;right:20px;bottom:20px;z-index:2147483000;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;font-size:14px;color:#212529}' +
      '.fab{width:56px;height:56px;border-radius:50%;border:0;background:' + c + ';color:#fff;font-size:26px;cursor:pointer;box-shadow:0 4px 14px rgba(0,0,0,.25)}' +
      '.p{display:none;flex-direction:column;width:340px;max-width:calc(100vw - 24px);height:480px;max-height:calc(100vh - 100px);background:#fff;border-radius:14px;box-shadow:0 8px 30px rgba(0,0,0,.28);overflow:hidden;margin-bottom:12px}' +
      '.w.o .p{display:flex}.h{background:' + c + ';color:#fff;padding:12px 14px;font-weight:600;display:flex;justify-content:space-between;align-items:center}.h button{background:none;border:0;color:#fff;font-size:20px;cursor:pointer}' +
      '.m{flex:1;overflow:auto;padding:12px;background:#f4f6f8;display:flex;flex-direction:column;gap:8px}.b{max-width:80%;padding:8px 11px;border-radius:12px;white-space:pre-wrap;word-wrap:break-word;line-height:1.35}' +
      '.b.agent{background:#fff;align-self:flex-start;border:1px solid #e3e6ea}.b.visitor{background:' + c + ';color:#fff;align-self:flex-end}' +
      '.i{display:flex;gap:6px;padding:8px;border-top:1px solid #e3e6ea}.i input{flex:1;padding:9px 10px;border:1px solid #ced4da;border-radius:8px;font:inherit}.i button{border:0;border-radius:8px;background:' + c + ';color:#fff;padding:0 14px;font:inherit;font-weight:600;cursor:pointer}' +
      '.k{padding:8px;border-top:1px solid #e3e6ea;display:flex;flex-direction:column;gap:6px}.k input{padding:8px;border:1px solid #ced4da;border-radius:8px;font:inherit}.hp{position:absolute;left:-9999px}.er{color:#dc3545;font-size:12px;padding:0 10px 6px}']));
    var w = el('div', { class: 'w' }), panel = el('div', { class: 'p' }), list = el('div', { class: 'm' });
    var close = el('button', { 'aria-label': T.close }, ['×']), head = el('div', { class: 'h' }, [el('span', {}, [d.title]), close]);
    var err = el('div', { class: 'er' }), input = el('input', { type: 'text', placeholder: T.placeholder, maxlength: '1000' }), send = el('button', { type: 'button' }, [T.send]);
    var hp = el('input', { type: 'text', tabindex: '-1', autocomplete: 'off', class: 'hp', 'aria-hidden': 'true' });
    var nameIn = el('input', { type: 'text', placeholder: T.name, maxlength: '80' }), mailIn = el('input', { type: 'email', placeholder: T.email, maxlength: '190' });
    var known = store.get('sama_wc_k_' + KEY) === '1';
    var ask = el('div', { class: 'k' }, [nameIn, mailIn]);
    panel.appendChild(head); panel.appendChild(list);
    if (d.ask_contact && !known) panel.appendChild(ask);
    panel.appendChild(err); panel.appendChild(el('div', { class: 'i' }, [input, send, hp]));
    var fab = el('button', { class: 'fab', 'aria-label': T.open }, ['💬']);
    w.appendChild(panel); w.appendChild(fab); root.appendChild(w);

    var last = 0, seen = {}, timer = null;
    function add(m) { if (seen[m.id]) return; seen[m.id] = 1; last = Math.max(last, m.id); list.appendChild(el('div', { class: 'b ' + m.from }, [m.text])); list.scrollTop = list.scrollHeight; }
    function poll() { fetch(API + '/messages?visitor=' + visitor + '&after=' + last, { headers: { Accept: 'application/json' } }).then(function (r) { return r.json(); }).then(function (j) { (j.messages || []).forEach(add); }).catch(function () {}); }
    function startPolling() { if (timer) return; poll(); timer = setInterval(function () { if (!document.hidden) poll(); }, 4000); }
    function open(on) { w.classList.toggle('o', on); if (on) { startPolling(); input.focus(); } }
    fab.addEventListener('click', function () { open(!w.classList.contains('o')); });
    close.addEventListener('click', function () { open(false); });
    list.appendChild(el('div', { class: 'b agent' }, [d.welcome]));
    if (store.get('sama_wc_open_' + KEY) === '1') { /* conversation already started: keep polling in the background */ }

    function submit() {
      var text = input.value.trim(); if (!text) return; err.textContent = ''; send.disabled = true;
      var body = { visitor: visitor, text: text, page: (window.location.href || '').slice(0, 300), client_id: rid(24), _hp: hp.value };
      if (d.ask_contact && !known) { body.name = nameIn.value.trim(); body.email = mailIn.value.trim(); }
      fetch(API + '/messages', { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body) })
        .then(function (r) { if (!r.ok) throw 0; return r.json(); })
        .then(function () { input.value = ''; if (ask.parentNode) { ask.parentNode.removeChild(ask); } known = true; store.set('sama_wc_k_' + KEY, '1'); startPolling(); poll(); })
        .catch(function () { err.textContent = T.error; }).then(function () { send.disabled = false; });
    }
    send.addEventListener('click', submit);
    input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); submit(); } });
    if (store.get('sama_wc_k_' + KEY) === '1') startPolling();
  }
})();
