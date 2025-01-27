@extends('layouts.app-master')

@section('content')
    <div class="bg-light p-5 rounded">
    @include('layouts.partials.messages')
        @auth
        <h1>Dashboard</h1>
        <p class="lead">Only authenticated users can access this section.</p>
        <a class="btn btn-lg btn-primary" href="https://codeanddeploy.com" role="button">View more tutorials here &raquo;</a>
        @endauth

        @guest
                @include('layouts.partials.messages')

        <h1>Homepage</h1>
        <p class="lead">Your viewing the home page. Please login to view the restricted data.</p>
        <p>Entre em contato 5595981110695 ou marcel.nagm@gmail.com para ativa-la</p>
        @endguest
    </div>
@endsection
