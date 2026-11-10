@extends('layouts.app-master')

@section('template_title', __('Website chat'))

@section('content')
<div class="container-fluid py-4">
  <div class="row justify-content-center"><div class="col-lg-9">
    <h1 class="h3 mb-3 fw-bold"><i class="bi bi-chat-dots-fill me-2 text-secondary"></i>{{ __('Website chat') }}</h1>
    <p class="text-muted">{{ __('A chat bubble for your website. Visitors write, the conversation appears in the Chat with the other channels, the AI agent answers and your team can take over.') }}</p>

    @include('layouts.partials.messages')

    <form method="POST" action="{{ route('webchat.store') }}" class="card card-body shadow-sm mb-4">
      @csrf
      <div class="row g-2 align-items-end">
        <div class="col-md-8"><label class="form-label">{{ __('Name of the site') }}</label><input name="name" class="form-control" required maxlength="80" placeholder="mystore.com"></div>
        <div class="col-md-4 d-grid"><button class="btn btn-primary">{{ __('Create chat widget') }}</button></div>
      </div>
    </form>

    @foreach ($widgets as $w)
      @php $c = $cfg($w); @endphp
      <div class="card shadow-sm mb-3"><div class="card-body">
        <form method="POST" action="{{ route('webchat.update', $w->id) }}" class="row g-3">
          @csrf @method('PUT')
          <div class="col-md-4"><label class="form-label">{{ __('Name of the site') }}</label><input name="name" class="form-control" value="{{ $w->name }}" required maxlength="80"></div>
          <div class="col-md-4"><label class="form-label">{{ __('Window title') }}</label><input name="title" class="form-control" value="{{ $c['title'] }}" maxlength="60" placeholder="{{ __('Chat with us') }}"></div>
          <div class="col-md-4"><label class="form-label">{{ __('Colour') }}</label><input type="color" name="color" class="form-control form-control-color" value="{{ \App\Services\Webchat\Webchat::color($c['color']) }}"></div>
          <div class="col-12"><label class="form-label">{{ __('Welcome message') }}</label><input name="welcome" class="form-control" value="{{ $c['welcome'] }}" maxlength="300" placeholder="{{ __('Hi! How can we help you?') }}"></div>
          <div class="col-md-8"><label class="form-label">{{ __('Sites allowed to show it (optional)') }}</label><input name="origins" class="form-control" value="{{ $c['origins'] }}" maxlength="500" placeholder="mystore.com, www.mystore.com"><div class="form-text">{{ __('Empty = any site. Otherwise only these domains (and their subdomains) can use the widget.') }}</div></div>
          <div class="col-md-4 pt-4">
            <div class="form-check form-switch"><input type="checkbox" class="form-check-input" name="active" value="1" id="a{{ $w->id }}" @checked($c['active'])><label class="form-check-label" for="a{{ $w->id }}">{{ __('Active') }}</label></div>
            <div class="form-check form-switch"><input type="checkbox" class="form-check-input" name="ask_contact" value="1" id="k{{ $w->id }}" @checked($c['ask_contact'])><label class="form-check-label" for="k{{ $w->id }}">{{ __('Ask name and e-mail') }}</label></div>
          </div>
          <div class="col-12"><label class="form-label">{{ __('Paste this before </body> on your site') }}</label>
            <input class="form-control font-monospace" readonly onclick="this.select()" value="{{ '<script src="' . route('webchat.script', $w->instance_name) . '" async></script>' }}"></div>
          <div class="col-12 d-flex gap-2"><button class="btn btn-primary">{{ __('Save') }}</button></form>
            <form method="POST" action="{{ route('webchat.destroy', $w->id) }}" onsubmit="return confirm('{{ __('Delete?') }}')">@csrf @method('DELETE')<button class="btn btn-outline-danger">{{ __('Delete') }}</button></form></div>
      </div></div>
    @endforeach
    <div class="small text-muted">{{ __('To have an AI agent answer visitors, create it in AI and assign it to the widget (it appears in the list like any number).') }}</div>
  </div></div>
</div>
@endsection
