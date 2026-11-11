<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>{{ __('Stop receiving messages') }} · {{ $company }}</title>
<style>
  :root{--bg:#f4f6f8;--card:#fff;--text:#1d2733;--muted:#6b7785;--line:#e3e8ee;--accent:#0d6efd;--ok:#198754;--bad:#dc3545}
  *{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--text);font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;min-height:100vh;display:grid;place-items:center;padding:16px}
  .card{background:var(--card);border-radius:14px;padding:28px;max-width:440px;width:100%;box-shadow:0 1px 3px rgba(0,0,0,.08);text-align:center}
  h1{font-size:1.3rem;margin:.4rem 0}.muted{color:var(--muted);font-size:.92rem}.ico{font-size:2.4rem}
  textarea{width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;font:inherit;margin:12px 0;resize:vertical;background:transparent;color:var(--text)}
  button{font:inherit;padding:11px 20px;border:0;border-radius:8px;cursor:pointer;color:#fff;background:var(--bad);width:100%}button.alt{background:transparent;color:var(--accent);border:1px solid var(--accent)}
  @media (prefers-color-scheme:dark){:root{--bg:#10161d;--card:#18212b;--text:#e8edf2;--muted:#93a1b0;--line:#2a3643}}
</style>
</head>
<body>
<div class="card">
  @if ($done === 'out' || ($out && $done === null))
    <div class="ico">✅</div>
    <h1>{{ __('You will not receive more messages from :company.', ['company' => $company]) }}</h1>
    <p class="muted">{{ __('It may take a few minutes to take effect. If you did this by mistake, you can undo it below.') }}</p>
    <form method="POST" action="{{ $undoUrl }}">@csrf<button class="alt" type="submit">{{ __('I changed my mind: send me messages again') }}</button></form>
  @elseif ($done === 'in')
    <div class="ico">👋</div>
    <h1>{{ __('Welcome back! You will receive messages from :company again.', ['company' => $company]) }}</h1>
  @else
    <div class="ico">✉️</div>
    <h1>{{ __('Stop receiving messages from :company?', ['company' => $company]) }}</h1>
    <p class="muted">{{ __('You will no longer receive campaigns or automatic replies on any channel.') }}</p>
    <form method="POST" action="{{ $confirmUrl }}">@csrf
      <textarea name="reason" rows="2" maxlength="300" placeholder="{{ __('Why? (optional)') }}"></textarea>
      <button type="submit">{{ __('Yes, stop messages') }}</button>
    </form>
  @endif
</div>
</body>
</html>
