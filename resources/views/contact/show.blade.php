@extends('layouts.app-master')

@section('template_title')
    {{ $contact->name ?? 'Show Contact' }}
@endsection

@section('content')
    <section class="content container-fluid">
        <div class="row">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header">
                        <div class="float-left">
                            <span class="card-title">Show Contact</span>
                        </div>
                        <div class="float-right">
                            <a class="btn btn-primary" href="{{ route('contacts.index') }}"> Back</a>
                        </div>
                    </div>

                    <div class="card-body">
                        
                        <div class="form-group">
                            <strong>Name:</strong>
                            {{ $contact->name }}
                        </div>
                        <div class="form-group">
                            <strong>Contact:</strong>
                            {{ $contact->contact }}
                        </div>
                        <div class="form-group">
                            <strong>User Id:</strong>
                            {{ $contact->user_id }}
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
