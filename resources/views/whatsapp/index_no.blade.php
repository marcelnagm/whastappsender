@extends('layouts.app-master')
@section('admin_title')
{{ config('settings.url_route')." ".__('Management')}}
@endsection
@section('content')

<div class="header bg-gradient-primary pb-8 pt-5 pt-mtsd-8">
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
                <div class="_29lv_">
                    <div class="landing-title _24CXv">Para usar o WhatsApp no nosso sistema:</div><ol class="_2yDOs"><li class="ZJ0wv">Abra o WhatsApp no seu celular</li><li class="ZJ0wv"><span dir="ltr" class="_3-8er">Toque em <strong><span dir="ltr" class="_3-8er">Mais opções <span class="_2zr6K"><svg height="24px" viewBox="0 0 24 24" width="24px"><rect fill="#f2f2f2" height="24" rx="3" width="24"></rect><path d="m12 15.5c.825 0 1.5.675 1.5 1.5s-.675 1.5-1.5 1.5-1.5-.675-1.5-1.5.675-1.5 1.5-1.5zm0-2c-.825 0-1.5-.675-1.5-1.5s.675-1.5 1.5-1.5 1.5.675 1.5 1.5-.675 1.5-1.5 1.5zm0-5c-.825 0-1.5-.675-1.5-1.5s.675-1.5 1.5-1.5 1.5.675 1.5 1.5-.675 1.5-1.5 1.5z" fill="#818b90"></path></svg></span></span></strong> ou <strong><span dir="ltr" class="_3-8er">Configurações <span class="_2zr6K"><svg width="24" height="24" viewBox="0 0 24 24"><rect fill="#F2F2F2" width="24" height="24" rx="3"></rect><path d="M12 18.69c-1.08 0-2.1-.25-2.99-.71L11.43 14c.24.06.4.08.56.08.92 0 1.67-.59 1.99-1.59h4.62c-.26 3.49-3.05 6.2-6.6 6.2zm-1.04-6.67c0-.57.48-1.02 1.03-1.02.57 0 1.05.45 1.05 1.02 0 .57-.47 1.03-1.05 1.03-.54.01-1.03-.46-1.03-1.03zM5.4 12c0-2.29 1.08-4.28 2.78-5.49l2.39 4.08c-.42.42-.64.91-.64 1.44 0 .52.21 1 .65 1.44l-2.44 4C6.47 16.26 5.4 14.27 5.4 12zm8.57-.49c-.33-.97-1.08-1.54-1.99-1.54-.16 0-.32.02-.57.08L9.04 5.99c.89-.44 1.89-.69 2.96-.69 3.56 0 6.36 2.72 6.59 6.21h-4.62zM12 19.8c.22 0 .42-.02.65-.04l.44.84c.08.18.25.27.47.24.21-.03.33-.17.36-.38l.14-.93c.41-.11.82-.27 1.21-.44l.69.61c.15.15.33.17.54.07.17-.1.24-.27.2-.48l-.2-.92c.35-.24.69-.52.99-.82l.86.36c.2.08.37.05.53-.14.14-.15.15-.34.03-.52l-.5-.8c.25-.35.45-.73.63-1.12l.95.05c.21.01.37-.09.44-.29.07-.2.01-.38-.16-.51l-.73-.58c.1-.4.19-.83.22-1.27l.89-.28c.2-.07.31-.22.31-.43s-.11-.35-.31-.42l-.89-.28c-.03-.44-.12-.86-.22-1.27l.73-.59c.16-.12.22-.29.16-.5-.07-.2-.23-.31-.44-.29l-.95.04c-.18-.4-.39-.77-.63-1.12l.5-.8c.12-.17.1-.36-.03-.51-.16-.18-.33-.22-.53-.14l-.86.35c-.31-.3-.65-.58-.99-.82l.2-.91c.03-.22-.03-.4-.2-.49-.18-.1-.34-.09-.48.01l-.74.66c-.39-.18-.8-.32-1.21-.43l-.14-.93a.426.426 0 00-.36-.39c-.22-.03-.39.05-.47.22l-.44.84-.43-.02h-.22c-.22 0-.42.01-.65.03l-.44-.84c-.08-.17-.25-.25-.48-.22-.2.03-.33.17-.36.39l-.13.88c-.42.12-.83.26-1.22.44l-.69-.61c-.15-.15-.33-.17-.53-.06-.18.09-.24.26-.2.49l.2.91c-.36.24-.7.52-1 .82l-.86-.35c-.19-.09-.37-.05-.52.13-.14.15-.16.34-.04.51l.5.8c-.25.35-.45.72-.64 1.12l-.94-.04c-.21-.01-.37.1-.44.3-.07.2-.02.38.16.5l.73.59c-.1.41-.19.83-.22 1.27l-.89.29c-.21.07-.31.21-.31.42 0 .22.1.36.31.43l.89.28c.03.44.1.87.22 1.27l-.73.58c-.17.12-.22.31-.16.51.07.2.23.31.44.29l.94-.05c.18.39.39.77.63 1.12l-.5.8c-.12.18-.1.37.04.52.16.18.33.22.52.14l.86-.36c.3.31.64.58.99.82l-.2.92c-.04.22.03.39.2.49.2.1.38.08.54-.07l.69-.61c.39.17.8.33 1.21.44l.13.93c.03.21.16.35.37.39.22.03.39-.06.47-.24l.44-.84c.23.02.44.04.66.04z" fill="#818b90"></path></svg></span></span></strong> e selecione <strong>WhatsApp Web</strong></span></li>
                        <li class="ZJ0wv">Aponte seu celular para essa tela para capturar o código</li>
                    </ol></div>
                <div id='qr_section' >
                    <input type="button" onclick="getQR();" class="btn btn-primary" value="Atualizar QR">
                    <p>Ao habilitar este recurso o sistema irá enviar notificações ao cliente
                        em relação ao pedido, mantenha este dispositivo ligado e conectado
                        para que este recurso funcione.</p>

                    <div id="qr" >
                    {{!is_array($res) ? 'Não foi possivel conectar ao serviço' : ''; }}
                        <img src="{{is_array($res) ? $res['base64'] : ''; }} ">
                    </div>

                </div>
            </div>


        </div>

    </div>
    @endsection

    @section('js')
    <?php
    $protocol = env("WHATSAPP_PROTOCOL", "somedefaultvalue");
    $hostname = env("WHATSAPP_URL", "somedefaultvalue");
    $port = env("WHATSAPP_PORT", "somedefaultvalue");
    ?>

    <script>

        var urlStatus = '{{$protocol}}://{{$hostname}}:{{$port}}/status';
        var qrcod_lido = false;

        var session = "{{auth()->user()->contact()}}";


        $(document).ready(function ($) {
            $.ajax({
                type: 'get',
                url: '{{$protocol}}://{{$hostname}}:{{$port}}/sessions/' + session + '/status',
//                        dataType: 'json',null,
                success: function (response) {
                    console.log(response);
                    document.location.reload(true);      

                }, error: function (response) {
                    //alert(response.responseJSON.errMsg);
                    qrcod_lido = false;
                    getQR();
                    // setInterval("atualizar_qrode()", 1000);
                }
            });
        });

        var urlStartSession = '{{$protocol}}://{{$hostname}}:{{$port}}/sessions/add';
        // Definindo intervalo que a função será chamada
        function atualizar_qrode() {
            $.ajax({
                type: 'get',
                url: '{{$protocol}}://{{$hostname}}:{{$port}}/sessions/' + session + '/status',
//                        dataType: 'json',null,
                success: function (response) {
                    console.log(response);
                    qrcod_lido = true;
 //                   document.location.reload(true);

                }, error: function (response) {
                    qrcod_lido = false;
                }
            });
            if (!qrcod_lido) {
                getQR();
            }
        }


        function getQR() {
            remove_session();
//            $("#qr").attr("src", '');
            $.ajax({
                url: urlStartSession,
                type: 'POST',
                data: JSON.stringify({sessionId: session}),
                contentType: 'application/json',
                success: function (data) {
                    var image = new Image();
                    console.log(data.qr);
                    image.src = data.qr;
                    $("#qr").html(image);
                }
            });

        }


        var urlRemove = '{{$protocol}}://{{$hostname}}:{{$port}}/sessions/' + session;

        function remove_session() {
            $.ajax(urlRemove, {
                type: 'DELETE',
                success: function (data) {
//                    document.location.reload(true);
                }
            }
            );
        }
    </script>

    @endsection

