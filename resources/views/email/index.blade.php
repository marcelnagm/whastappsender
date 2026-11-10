@extends('layouts.app-master')

@section('template_title', __('Email'))

@section('content')
<div class="container-fluid py-4">
  <div class="row justify-content-center"><div class="col-lg-9">
    <h1 class="h3 mb-4 fw-bold"><i class="bi bi-envelope-fill me-2 text-secondary"></i>{{ __('Email') }}</h1>

    @if (session('success'))<div class="alert alert-success border-0">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="alert alert-danger border-0">{{ session('error') }}</div>@endif

    <div class="card shadow-sm mb-4"><div class="card-body">
      <h2 class="h5">{{ __('Connect a mailbox') }}</h2>
      <p class="small text-muted">{{ __('Use the mailbox your customers already write to. SMTP sends your messages and the AI answers. IMAP (optional) brings incoming mail into the chat, where it is joined with the contact that has the same email address.') }}</p>
      <div class="mb-3 small">
        {{ __('Quick setup') }}:
        <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="gmail">Gmail</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="outlook">Outlook / Microsoft 365</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="zoho">Zoho</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-preset="yahoo">Yahoo</button>
        <div class="text-muted mt-1">{{ __('Gmail, Outlook and Yahoo need an app password (not your normal password): create it in the security settings of the account.') }}</div>
      </div>

      @error('email')<div class="alert alert-danger border-0">{{ $message }}</div>@enderror
      @if ($errors->any() && !$errors->has('email'))<div class="alert alert-danger border-0">{{ $errors->first() }}</div>@endif

      <form method="POST" action="{{ route('email.store') }}" id="emailForm" class="row g-3" autocomplete="off">
        @csrf
        <div class="col-md-6"><label class="form-label">{{ __('Email address') }}</label><input type="email" name="email" class="form-control" value="{{ old('email') }}" required></div>
        <div class="col-md-6"><label class="form-label">{{ __('Sender name') }}</label><input name="from_name" class="form-control" value="{{ old('from_name') }}" placeholder="{{ config('app.name') }}"></div>

        <div class="col-12"><h3 class="h6 mb-0">{{ __('Sending (SMTP)') }}</h3></div>
        <div class="col-md-5"><label class="form-label">{{ __('Server') }}</label><input name="smtp_host" class="form-control" value="{{ old('smtp_host') }}" placeholder="smtp.example.com" required></div>
        <div class="col-md-2"><label class="form-label">{{ __('Port') }}</label><input type="number" name="smtp_port" class="form-control" value="{{ old('smtp_port', 587) }}" required></div>
        <div class="col-md-5"><label class="form-label">{{ __('Security') }}</label>
          <select name="smtp_encryption" class="form-select"><option value="tls" @selected(old('smtp_encryption', 'tls') === 'tls')>STARTTLS (587)</option><option value="ssl" @selected(old('smtp_encryption') === 'ssl')>SSL/TLS (465)</option><option value="none" @selected(old('smtp_encryption') === 'none')>{{ __('None') }}</option></select></div>
        <div class="col-md-6"><label class="form-label">{{ __('Username') }}</label><input name="smtp_username" class="form-control" value="{{ old('smtp_username') }}" required></div>
        <div class="col-md-6"><label class="form-label">{{ __('Password') }}</label><input type="password" name="smtp_password" class="form-control" autocomplete="new-password" required></div>

        <div class="col-12"><h3 class="h6 mb-0">{{ __('Receiving (IMAP, optional)') }}</h3><div class="form-text">{{ __('Leave the server empty to only send. Username and password default to the SMTP ones.') }}</div></div>
        <div class="col-md-5"><label class="form-label">{{ __('Server') }}</label><input name="imap_host" class="form-control" value="{{ old('imap_host') }}" placeholder="imap.example.com"></div>
        <div class="col-md-2"><label class="form-label">{{ __('Port') }}</label><input type="number" name="imap_port" class="form-control" value="{{ old('imap_port', 993) }}"></div>
        <div class="col-md-5"><label class="form-label">{{ __('Security') }}</label>
          <select name="imap_encryption" class="form-select"><option value="ssl" @selected(old('imap_encryption', 'ssl') === 'ssl')>SSL/TLS (993)</option><option value="tls" @selected(old('imap_encryption') === 'tls')>STARTTLS (143)</option></select></div>
        <div class="col-md-6"><label class="form-label">{{ __('Username') }}</label><input name="imap_username" class="form-control" value="{{ old('imap_username') }}"></div>
        <div class="col-md-6"><label class="form-label">{{ __('Password') }}</label><input type="password" name="imap_password" class="form-control" autocomplete="new-password"></div>

        <div class="col-12 d-grid d-md-block"><button class="btn btn-primary px-4">{{ __('Test and connect') }}</button></div>
      </form>
      <div class="small text-muted mt-3">{{ __('Only messages that arrive after you connect are imported. The server is checked about every minute (cron: :cron). Passwords are stored encrypted.', ['cron' => $fetchCron]) }}</div>
    </div></div>

    <div class="card shadow-sm">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead class="table-light"><tr><th>{{ __('Account') }}</th><th>{{ __('Receiving') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
          <tbody>
          @forelse ($accounts as $acc)
            <tr>
              <td class="fw-semibold">{{ $acc->email_address }}<div class="small text-muted">{{ $acc->smtp_host }}:{{ $acc->smtp_port }}</div></td>
              <td class="small">@if ($acc->imap_host){{ $acc->imap_host }}<div class="text-muted">{{ __('Last check') }}: {{ $acc->email_checked_at ? $acc->email_checked_at->diffForHumans() : '—' }}</div>@else<span class="text-muted">{{ __('Send only') }}</span>@endif</td>
              <td>@if ($acc->status === 'connected')<span class="badge bg-success">{{ __('Connected') }}</span>@else<span class="badge bg-danger">{{ __('Problem') }}</span><div class="small text-danger">{{ $acc->email_error }}</div>@endif</td>
              <td class="text-end text-nowrap">
                <form method="POST" action="{{ route('email.refresh', $acc) }}" class="d-inline">@csrf
                  <button class="btn btn-sm btn-outline-secondary">{{ __('Test again') }}</button>
                </form>
                <form method="POST" action="{{ route('email.destroy', $acc) }}" class="d-inline" onsubmit="return confirm('{{ __('Remove this account?') }}')">@csrf @method('DELETE')
                  <button class="btn btn-sm btn-outline-danger">{{ __('Remove') }}</button>
                </form>
              </td>
            </tr>
            <tr><td colspan="4" class="bg-light border-top-0 pt-0">
              <form method="POST" action="{{ route('email.settings', $acc) }}" class="row g-2 align-items-end small">
                @csrf @method('PUT')
                <div class="col-md-4"><label class="form-label mb-0">{{ __('New conversations go to') }}</label>
                  <select name="default_assignee" class="form-select form-select-sm"><option value="">{{ __('Shared: distribute as set in the inbox') }}</option>
                    @foreach ($people as $p)<option value="{{ $p->id }}" @selected((int) $acc->default_assignee === (int) $p->id)>{{ $p->name }}</option>@endforeach</select></div>
                <div class="col-md-6"><label class="form-label mb-0">{{ __('Signature of the e-mails sent from this mailbox') }}</label>
                  <textarea name="email_signature" rows="2" maxlength="600" class="form-control form-control-sm" placeholder="{{ __('Name, role, phone, site...') }}">{{ $acc->email_signature }}</textarea></div>
                <div class="col-md-2 d-grid"><button class="btn btn-sm btn-outline-primary">{{ __('Save') }}</button></div>
              </form>
            </td></tr>
          @empty
            <tr><td colspan="4" class="text-center text-muted py-4">{{ __('No mailbox connected yet.') }}</td></tr>
          @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div></div>
</div>
<script>
(function () {
  var P = {
    gmail:   { smtp_host: 'smtp.gmail.com', smtp_port: 465, smtp_encryption: 'ssl', imap_host: 'imap.gmail.com', imap_port: 993, imap_encryption: 'ssl' },
    outlook: { smtp_host: 'smtp.office365.com', smtp_port: 587, smtp_encryption: 'tls', imap_host: 'outlook.office365.com', imap_port: 993, imap_encryption: 'ssl' },
    zoho:    { smtp_host: 'smtp.zoho.com', smtp_port: 465, smtp_encryption: 'ssl', imap_host: 'imap.zoho.com', imap_port: 993, imap_encryption: 'ssl' },
    yahoo:   { smtp_host: 'smtp.mail.yahoo.com', smtp_port: 465, smtp_encryption: 'ssl', imap_host: 'imap.mail.yahoo.com', imap_port: 993, imap_encryption: 'ssl' }
  };
  var f = document.getElementById('emailForm');
  document.querySelectorAll('[data-preset]').forEach(function (b) {
    b.addEventListener('click', function () {
      var p = P[b.getAttribute('data-preset')];
      Object.keys(p).forEach(function (k) { if (f.elements[k]) f.elements[k].value = p[k]; });
      var u = f.elements.email.value; if (u) { f.elements.smtp_username.value = u; }
    });
  });
  f.elements.email.addEventListener('blur', function () { if (!f.elements.smtp_username.value) f.elements.smtp_username.value = f.elements.email.value; });
})();
</script>
@endsection
