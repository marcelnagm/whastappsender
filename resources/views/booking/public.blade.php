<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>{{ $cfg->title }} · {{ $company }}</title>
<style>:root{--bg:#f4f6f8;--card:#fff;--text:#1d2733;--muted:#6b7785;--line:#e3e8ee;--accent:#0d6efd;--bad:#dc3545}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:16px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif}.wrap{max-width:640px;margin:0 auto;padding:16px}.card{background:var(--card);border-radius:12px;padding:20px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.08)}h1{font-size:1.4rem;margin:0 0 4px}.muted{color:var(--muted);font-size:.9rem}.chips a,.chips label{display:inline-block;margin:0 6px 6px 0;padding:6px 12px;border:1px solid var(--line);border-radius:99px;color:var(--text);text-decoration:none;cursor:pointer}.chips a.on,.chips input:checked+span{background:var(--accent);color:#fff;border-color:var(--accent)}.chips input{display:none}.chips span{display:inline-block;padding:6px 12px;border:1px solid var(--line);border-radius:99px;margin:0 6px 6px 0}input[type=text],input[type=email],input[type=tel]{width:100%;padding:10px;border:1px solid var(--line);border-radius:8px;font:inherit;margin:4px 0 10px}button{font:inherit;padding:10px 18px;border:0;border-radius:8px;cursor:pointer;color:#fff;background:var(--accent)}button.alt{background:transparent;color:var(--bad);border:1px solid var(--bad)}.err{color:var(--bad)}@media (prefers-color-scheme:dark){:root{--bg:#10161d;--card:#18212b;--text:#e8edf2;--muted:#93a1b0;--line:#2a3643}input{background:#10161d;color:var(--text)}}</style>
</head><body><div class="wrap">
<div class="card"><div class="muted">{{ $company }}</div><h1>{{ $cfg->title }}</h1><div class="muted">{{ $cfg->duration_minutes }} min · {{ $tz }}</div>@if ($cfg->description)<p>{{ $cfg->description }}</p>@endif</div>
@if (!$days)
  <div class="card">{{ __('No times available right now.') }}</div>
@else
<div class="card">
  <div class="chips">@foreach ($days as $d)<a href="{{ route('booking-public.show', $cfg->slug) }}?date={{ $d->format('Y-m-d') }}" class="{{ $d->format('Y-m-d') === $date->format('Y-m-d') ? 'on' : '' }}">{{ $d->format('d/m') }}</a>@endforeach</div>
</div>
<form method="POST" action="{{ route('booking-public.store', $cfg->slug) }}" class="card">
  @csrf
  <div class="chips">@foreach ($slots as $s)<label><input type="radio" name="starts_at" value="{{ $s->copy()->utc()->toIso8601String() }}" required><span>{{ $s->format('H:i') }}</span></label>@endforeach</div>
  @error('starts_at')<div class="err">{{ $message }}</div>@enderror
  <label>{{ __('Your name') }}<input type="text" name="name" required maxlength="120" value="{{ old('name') }}"></label>
  <label>{{ __('Phone / WhatsApp') }}<input type="tel" name="phone" required maxlength="40" value="{{ old('phone') }}"></label>
  @error('phone')<div class="err">{{ $message }}</div>@enderror
  <label>{{ __('E-mail (optional)') }}<input type="email" name="email" maxlength="160" value="{{ old('email') }}"></label>
  <button>{{ __('Confirm booking') }}</button>
</form>
@endif
</div></body></html>
