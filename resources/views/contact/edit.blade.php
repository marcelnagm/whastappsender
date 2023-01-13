@extends('layouts.app-master')

@section('template_title')
    Update Contact
@endsection

@section('content')
    <section class="content container-fluid">
        <div class="">
            <div class="col-md-12">

                @includeif('partials.errors')

                <div class="card card-default">
                    <div class="card-header">
                        <span class="card-title">Update Contact</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('contacts.update', $contact->id) }}"  role="form" enctype="multipart/form-data">
                            {{ method_field('PATCH') }}
                            @csrf

                            @include('contact.form')

                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
