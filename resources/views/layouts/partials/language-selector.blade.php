{{--
    Language selector.
    Usage:
      @include('layouts.partials.language-selector')                            -> Bootstrap dropdown (needs bootstrap JS, used in the navbar)
      @include('layouts.partials.language-selector', ['variant' => 'inline'])  -> plain <select>, works without JS libraries (login/register/guest pages)
--}}
@php
    $languages = config('languages.supported', []);
    $currentLocale = app()->getLocale();
    $current = $languages[$currentLocale] ?? reset($languages);
    $variant = $variant ?? 'dropdown';
@endphp

@if (count($languages) > 1)
    @if ($variant === 'inline')
        <select class="form-select form-select-sm language-select"
                aria-label="{{ __('Language') }}"
                onchange="if (this.value) { window.location.href = this.value; }">
            @foreach ($languages as $code => $language)
                <option value="{{ route('language.switch', $code) }}" {{ $code === $currentLocale ? 'selected' : '' }}>
                    {{ $language['flag'] }} {{ $language['native'] }}
                </option>
            @endforeach
        </select>
    @else
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-light dropdown-toggle" type="button"
                    id="languageDropdown" data-bs-toggle="dropdown" aria-expanded="false"
                    aria-label="{{ __('Language') }}" title="{{ __('Language') }}">
                {{ $current['flag'] }}
                <span class="d-none d-sm-inline ms-1">{{ $current['native'] }}</span>
            </button>
            <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end shadow-lg border-0"
                aria-labelledby="languageDropdown">
                @foreach ($languages as $code => $language)
                    <li>
                        <a class="dropdown-item {{ $code === $currentLocale ? 'active' : '' }}"
                           href="{{ route('language.switch', $code) }}"
                           hreflang="{{ $language['html'] }}" lang="{{ $language['html'] }}">
                            {{ $language['flag'] }} {{ $language['native'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
@endif
