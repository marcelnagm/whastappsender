@extends('layouts.app-master')
@section('admin_title')
{{ config('settings.url_route')." ".__('Management')}}
@endsection
@section('content')
<div class="header bg-gradient-primary pb-8 pt-5 pt-md-8 " style="width: 1024px">
</div>
<div class="container-fluid mt--7">
    <div class="row" style="width: 800px">
        <br/>
        <div class="card bg-secondary shadow col-12">
            <div class="card-header bg-white border-0 col-12" style="width: 1024px" >
                <div class="row align-items-center">
                    <div class="col-8">
                        <h3 class="mb-0">Whatsapp</h3>                            
                    </div>                       
                </div>
            </div>
            <div class="card-body bg-white border-0 col-lg-12">
                <table class="table align-items-center" >                           
                    @include('whatsapp.list_session')                                                  
                </table>
                <br>

            </div>


        </div>

    </div>
    @endsection

    @section('js')

    <script>
        $(document).ready(function ($) {
            //check if h

        });
<?php
$protocol = env("WHATSAPP_PROTOCOL", "somedefaultvalue");
$hostname = env("WHATSAPP_URL", "somedefaultvalue");
$port = env("WHATSAPP_PORT", "somedefaultvalue");
?>
        var session = "{{$device['session']}}";
        var urlRemove = '{{$protocol}}://{{$hostname}}:{{$port}}/sessions/' + session;

        function remove_session() {
            $.ajax(urlRemove, {
                type: 'DELETE',
                success: function (data) {
                    document.location.reload(true);
                }
            }
            );
        }

    </script>

    @endsection

