/*! SAMA Envios contact form. Usage: <script src="https://YOUR-DOMAIN/f/KEY.js" async></script>
   Optional: put <div data-sama-form="KEY"></div> where the form must appear (otherwise it is drawn right after the script tag). */
(function () {
  var API = '__API__', KEY = '__KEY__';
  var me = document.currentScript;
  function mount() {
    var host = document.querySelector('[data-sama-form="' + KEY + '"]');
    if (!host) { host = document.createElement('div'); (me && me.parentNode ? me.parentNode.insertBefore(host, me.nextSibling) : document.body.appendChild(host)); }
    if (host.__sama) return; host.__sama = true;
    var root = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
    fetch(API, { headers: { Accept: 'application/json' } }).then(function (r) { if (!r.ok) throw 0; return r.json(); }).then(function (d) { draw(root, d); }).catch(function () {});
  }
  function el(t, a, c) { var e = document.createElement(t); for (var k in (a || {})) e.setAttribute(k, a[k]); (c || []).forEach(function (x) { e.appendChild(typeof x === 'string' ? document.createTextNode(x) : x); }); return e; }
  function draw(root, d) {
    var acc = d.accent_color || '#0d6efd';
    var css = el('style', {}, ['*{box-sizing:border-box}.f{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;max-width:480px;color:#212529}h3{margin:0 0 4px;font-size:1.25rem}p.d{margin:0 0 12px;color:#6c757d;font-size:.9rem}label{display:block;font-size:.85rem;margin:10px 0 4px;font-weight:600}input,textarea,select{width:100%;padding:9px 10px;border:1px solid #ced4da;border-radius:6px;font:inherit}input:focus,textarea:focus,select:focus{outline:2px solid ' + acc + '55;border-color:' + acc + '}button{margin-top:14px;width:100%;padding:11px;border:0;border-radius:6px;background:' + acc + ';color:#fff;font:inherit;font-weight:600;cursor:pointer}button[disabled]{opacity:.6}.e{color:#dc3545;font-size:.8rem;margin-top:3px}.ok{padding:14px;border-radius:6px;background:#d1e7dd;color:#0f5132}.hp{position:absolute;left:-9999px;height:0;overflow:hidden}']);
    var form = el('form', { novalidate: 'novalidate', class: 'f' });
    if (d.title) form.appendChild(el('h3', {}, [d.title]));
    if (d.description) form.appendChild(el('p', { class: 'd' }, [d.description]));
    var inputs = {}, errs = {};
    d.fields.forEach(function (f) {
      var id = 'sama-' + KEY + '-' + f.key, inp;
      if (f.type === 'textarea') inp = el('textarea', { rows: 4 });
      else if (f.type === 'select') { inp = el('select'); inp.appendChild(el('option', { value: '' }, ['—'])); f.options.forEach(function (o) { inp.appendChild(el('option', { value: o }, [o])); }); }
      else inp = el('input', { type: f.type === 'tel' ? 'tel' : (f.type === 'email' ? 'email' : 'text') });
      inp.id = id; inp.name = f.key; if (f.placeholder) inp.setAttribute('placeholder', f.placeholder); if (f.required) inp.setAttribute('required', 'required');
      var er = el('div', { class: 'e' }); inputs[f.key] = inp; errs[f.key] = er;
      form.appendChild(el('label', { for: id }, [f.label + (f.required ? ' *' : '')])); form.appendChild(inp); form.appendChild(er);
    });
    var consent = null, consentErr = null;
    if (d.consent) {
      consent = el('input', { type: 'checkbox', id: 'sama-' + KEY + '-consent', style: 'width:auto;margin-right:8px' });
      consentErr = el('div', { class: 'e' });
      form.appendChild(el('label', { for: 'sama-' + KEY + '-consent', style: 'font-weight:400;margin-top:14px' }, [consent, d.consent]));
      form.appendChild(consentErr);
    }
    var hp = el('input', { type: 'text', name: '_hp', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' }); form.appendChild(el('div', { class: 'hp' }, [hp]));
    var btn = el('button', { type: 'submit' }, [d.submit_label]); form.appendChild(btn);
    form.addEventListener('submit', function (ev) {
      ev.preventDefault(); var body = { _hp: hp.value };
      if (consent) { consentErr.textContent = ''; if (!consent.checked) { consentErr.textContent = d.consent_error || ''; return; } body._consent = true; }
      Object.keys(inputs).forEach(function (k) { body[k] = inputs[k].value; errs[k].textContent = ''; });
      btn.disabled = true; var label = btn.textContent; btn.textContent = d.sending;
      fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(body) })
        .then(function (r) { return r.json().then(function (j) { return { s: r.status, j: j }; }); })
        .then(function (r) {
          if (r.s === 422) { var e = r.j.errors || {}; Object.keys(e).forEach(function (k) { if (errs[k]) errs[k].textContent = e[k][0]; }); if (e._consent && consentErr) consentErr.textContent = e._consent[0]; btn.disabled = false; btn.textContent = label; return; }
          if (!r.j.ok) throw 0;
          if (r.j.redirect) { window.location.href = r.j.redirect; return; }
          form.replaceWith(el('div', { class: 'f' }, [el('div', { class: 'ok' }, [r.j.message])]));
        })
        .catch(function () { btn.disabled = false; btn.textContent = label; });
    });
    root.appendChild(css); root.appendChild(form);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();
