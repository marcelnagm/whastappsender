@extends('layouts.app-master')

@section('template_title')
    Create Campaign
@endsection

@section('content')
    <section class="content container-fluid">
        <div class="row">
            <div class="col-md-12">

                @includeif('partials.errors')

                <div class="card card-default">
                    <div class="card-header">
                        <span class="card-title">Create Campaign</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('campaigns.store') }}"  role="form" enctype="multipart/form-data">
                            @csrf

                            @include('campaign.form')

                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
