@extends('layouts.app-master')
@section('admin_title')
{{ config('settings.url_route')." ".__('Management')}}
@endsection
@section('content')
<div class="header bg-gradient-primary pb-8 pt-5 pt-md-8">
</div>
<div class="container-fluid mt--7">
    <div class="row">
        <br/>
        <div class="card bg-secondary shadow col-lg-12">
            <div class="card-header bg-white border-0">
                <div class="row align-items-center">
                    <div class="col-8">
                        <h3 class="mb-0">Whatsapp</h3>                            
                    </div>                       
                </div>
            </div>
            <div class="card-body bg-white border-0">                
                <form id="restorant-form" method="post" action="{{ route('whatsapp.store') }}" autocomplete="off" enctype="multipart/form-data">
                    @include('whatsapp.form')
                </form>
            </div>

        </div>
        @include('layouts.footers.auth')
    </div>
    @endsection

    @section('js')

    <script>
        $(document).ready(function ($) {

                   });


    </script>

    @endsection

