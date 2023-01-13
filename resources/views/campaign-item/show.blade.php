@extends('layouts.app-master')

@section('template_title')
    {{ $campaignItem->name ?? 'Show Campaign Item' }}
@endsection

@section('content')
    <section class="content container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <div class="float-left">
                            <span class="card-title">Show Campaign Item</span>
                        </div>
                        <div class="float-right">
                            <a class="btn btn-primary" href="{{ route('campaign-items.index') }}"> Back</a>
                        </div>
                    </div>

                    <div class="card-body">
                        
                        <div class="form-group">
                            <strong>Name:</strong>
                            {{ $campaignItem->name }}
                        </div>
                        <div class="form-group">
                            <strong>Text:</strong>
                            {{ $campaignItem->text }}
                        </div>
                        <div class="form-group">
                            <strong>User Id:</strong>
                            {{ $campaignItem->user_id }}
                        </div>
                        <div class="form-group">
                            <strong>Campaign Id:</strong>
                            {{ $campaignItem->campaign_id }}
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
