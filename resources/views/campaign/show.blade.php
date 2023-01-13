@extends('layouts.app-master')

@section('template_title')
    {{ $campaign->name ?? 'Show Campaign' }}
@endsection

@section('content')
    <section class="content container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <div class="float-left">
                            <span class="card-title">Show Campaign</span>
                        </div>
                        <div class="float-right">
                            <a class="btn btn-primary" href="{{ route('campaigns.index') }}"> Back</a>
                        </div>
                    </div>

                    <div class="card-body">
                        
                        <div class="form-group">
                            <strong>Name:</strong>
                            {{ $campaign->name }}
                        </div>
                        <div class="form-group">
                            <strong>User Id:</strong>
                            {{ $campaign->user_id }}
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
