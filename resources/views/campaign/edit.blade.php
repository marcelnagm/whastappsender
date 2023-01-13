@extends('layouts.app-master')

@section('template_title')
    Update Campaign
@endsection

@section('content')
    <section class="content container-fluid">
        <div class="">
            <div class="col-md-12">

                @includeif('partials.errors')

                <div class="card card-default">
                    <div class="card-header">
                        <span class="card-title">Update Campaign</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('campaigns.update', $campaign->id) }}"  role="form" enctype="multipart/form-data">
                            {{ method_field('PATCH') }}
                            @csrf

                            @include('campaign.form')

                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
