@extends('layouts.app-master')

@section('template_title')
    Create Campaign Item
@endsection

@section('content')
    <section class="content container-fluid">
        <div class="row">
            <div class="col-md-12">

                @includeif('partials.errors')

                <div class="card card-default">
                    <div class="card-header">
                        <span class="card-title">Create Campaign Item</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('campaign-items.store') }}"  role="form" enctype="multipart/form-data">
                            @csrf

                            @include('campaign-item.form')

                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
