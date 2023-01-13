@extends('layouts.app-master')

@section('template_title')
    Update Campaign Item
@endsection

@section('content')
    <section class="content container-fluid">
        <div class="">
            <div class="col-md-12">

                @includeif('partials.errors')

                <div class="card card-default">
                    <div class="card-header">
                        <span class="card-title">Update Campaign Item</span>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="my_form" action="{{ route('campaign-items.update', $campaignItem->id) }}"  role="form" enctype="multipart/form-data">
                            {{ method_field('PATCH') }}
                            @csrf

                            @include('campaign-item.form')

                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
