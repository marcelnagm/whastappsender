<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>{{ $p->title }} · {{ $company }}</title>
<style>
  :root{--bg:#f4f6f8;--card:#fff;--text:#1d2733;--muted:#6b7785;--line:#e3e8ee;--accent:#0d6efd;--ok:#198754;--bad:#dc3545}
  *{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--text);font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
  .wrap{max-width:760px;margin:0 auto;padding:16px}.card{background:var(--card);border-radius:12px;padding:20px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
  h1{font-size:1.4rem;margin:0 0 4px}.muted{color:var(--muted);font-size:.9rem}table{width:100%;border-collapse:collapse}th,td{padding:8px 4px;border-bottom:1px solid var(--line);text-align:left}
  th:last-child,td:last-child,.r{text-align:right}.total{font-size:1.25rem;font-weight:700}.pre{white-space:pre-line}
  .badge{display:inline-block;padding:2px 10px;border-radius:99px;font-size:.8rem;color:#fff}.b-ok{background:var(--ok)}.b-bad{background:var(--bad)}.b-warn{background:#b7791f}
  input[type=text],textarea{width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;font:inherit}
  button{font:inherit;padding:10px 18px;border:0;border-radius:8px;cursor:pointer;color:#fff;background:var(--accent)}button.alt{background:transparent;color:var(--bad);border:1px solid var(--bad)}
  .row{display:flex;gap:12px;flex-wrap:wrap}.row>*{flex:1 1 280px}.err{color:var(--bad)}.pv{background:#fff3cd;padding:6px 12px;text-align:center;font-size:.85rem}
  @media (prefers-color-scheme:dark){:root{--bg:#10161d;--card:#18212b;--text:#e8edf2;--muted:#93a1b0;--line:#2a3643}input[type=text],textarea{background:#10161d;color:var(--text)}}
  @media print{body{background:#fff}.noprint{display:none!important}.card{box-shadow:none;border:1px solid var(--line)}}
</style>
</head>
<body>
@if ($preview)<div class="pv noprint">{{ __('Preview: the customer sees this page.') }}</div>@endif
@php $money = fn ($v) => number_format((float) $v, 2, ',', '.'); $cur = config('crm.currency', 'R$'); $expired = $p->isExpired(); @endphp
<div class="wrap">
  <div class="card">
    <div class="muted">{{ $company }} · {{ __('Proposal') }} #{{ $p->number }}</div>
    <h1>{{ $p->title }}</h1>
    <div class="muted">{{ $p->created_at->format('d/m/Y') }}@if ($p->valid_until) · {{ __('Valid until') }} {{ $p->valid_until->format('d/m/Y') }}@endif
      @if ($p->status === 'accepted') <span class="badge b-ok">{{ __('Accepted') }}</span>@elseif ($p->status === 'declined') <span class="badge b-bad">{{ __('Declined') }}</span>@elseif ($expired) <span class="badge b-warn">{{ __('Expired') }}</span>@endif</div>
    @if ($p->intro)<p class="pre">{{ $p->intro }}</p>@endif
  </div>

  <div class="card">
    <table>
      <thead><tr><th>{{ __('Item') }}</th><th class="r">{{ __('Qty') }}</th><th class="r">{{ __('Unit price') }}</th><th>{{ __('Total') }}</th></tr></thead>
      <tbody>
      @foreach ($p->items as $i)
        <tr><td>{{ $i['name'] }}@if ($i['discount_pct'] > 0)<div class="muted">−{{ rtrim(rtrim(number_format($i['discount_pct'], 2, ',', ''), '0'), ',') }}%</div>@endif</td>
          <td class="r">{{ rtrim(rtrim(number_format($i['quantity'], 2, ',', ''), '0'), ',') }}</td><td class="r">{{ $money($i['unit_price']) }}</td><td>{{ $money($i['total']) }}</td></tr>
      @endforeach
      </tbody>
    </table>
    <p class="r total">{{ __('Total') }}: {{ $cur }} {{ $money($p->total) }}</p>
  </div>

  @if ($p->terms)<div class="card"><strong>{{ __('Terms and conditions') }}</strong><p class="pre muted">{{ $p->terms }}</p></div>@endif

  @if (session('done'))<div class="card"><strong>{{ session('done') }}</strong></div>@endif
  @if ($errors->any())<div class="card err">{{ $errors->first() }}</div>@endif

  @if ($p->status === 'accepted')
    <div class="card">{{ __('Accepted by :name on :date.', ['name' => $p->answered_by, 'date' => optional($p->answered_at)->format('d/m/Y H:i')]) }}</div>
  @elseif ($p->status === 'declined')
    <div class="card">{{ __('This proposal was declined.') }}</div>
  @elseif ($expired)
    <div class="card">{{ __('This proposal has expired. Please contact us for a new one.') }}</div>
  @elseif (!$preview)
    <div class="card noprint">
      <form method="POST" action="{{ route('proposals.public.accept', $p->token) }}">@csrf
        <label for="n"><strong>{{ __('Accept this proposal') }}</strong></label>
        <input type="text" id="n" name="name" placeholder="{{ __('Your full name') }}" required minlength="3" maxlength="120" autocomplete="name" value="{{ old('name') }}">
        <p><label><input type="checkbox" name="agree" value="1" required> {{ __('I have read and accept the items, values and terms above.') }}</label></p>
        <button>{{ __('Accept') }}</button>
      </form>
    </div>
    <div class="card noprint">
      <form method="POST" action="{{ route('proposals.public.decline', $p->token) }}">@csrf
        <label for="r">{{ __('Not for you? Tell us why (optional)') }}</label>
        <textarea id="r" name="reason" rows="2" maxlength="300"></textarea>
        <p><button class="alt" onclick="return confirm('{{ __('Decline this proposal?') }}')">{{ __('Decline') }}</button></p>
      </form>
    </div>
  @endif

  <p class="muted noprint" style="text-align:center"><a href="#" onclick="window.print();return false" style="color:inherit">{{ __('Print or save as PDF') }}</a></p>
</div>
</body>
</html>
