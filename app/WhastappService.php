<?php

namespace App;

use App\Order;
use App\User;
use App\Extras;
use App\Models\Variants;
use App\Address;
use App\Models\OrderHasItems;
use App\Models\WhatsappMessage;
use App\Models\MyModel;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;

class WhastappService
{

    public static function getMobileInfo($name)
    {
        $protocol = env("WHATSAPP_PROTOCOL", "somedefaultvalue");
        $hostname = env("WHATSAPP_URL", "somedefaultvalue");
        $port = env("WHATSAPP_PORT", "somedefaultvalue");


        //        $protocol+'://'+$hostname+':'+$port+'/getHostDevice

        $ch = curl_init($protocol . '://' . $hostname . ':' . $port . '/getHostDevice');
        # Setup request to send json via POST.
        $payload = json_encode(
            array(
                "SessionName" => $name,
                "AuthorizationToken" => "podecolocarqualquercoisa"
            )
        );
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        //# Return response instead of printing.
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15); //timeout in seconds
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        //# Send request.
        $result = curl_exec($ch);
        dd($result);
        $result = json_decode($result, true);
        curl_close($ch);


        $device = array(
            'session' => $result['wid']['user'],
            'hw' => $result['phone']['device_manufacturer'] . ' - ' . $result['platform'],
            'batt' => $result['plugged'] ? $result['battery'] . '-' . 'Carregando' : $result['battery'] . '-' . 'Descarregando',
            'respond' => $result['isResponse'] ? 'Está Respondendo' : 'Não Responde',
        );

        //# Print response. 
        return $device;
    }

    public static function delete($name, $status = false)
    {

        $hostname = env("WHATSAPP_URL", "somedefaultvalue");
        $port = env("WHATSAPP_PORT", "somedefaultvalue");
        $apikey = env("WHATSAPP_APIKEY", "somedefaultvalue");
        try {
            $client = new Client();
            $headers = [
                'apikey' => $apikey
            ];
            $request = new Request('DELETE', $hostname . ':' . $port . '/instance/logout/' . $name, $headers);
            $res = $client->sendAsync($request)->wait();
            $res = json_decode($res->getBody(), true);
        } catch (ClientException $ex) {
            return true;
        }catch( ConnectException $ex){
            return false;
        }
    }

    public static function isConnected($name, $status = false)
    {


        $hostname = env("WHATSAPP_URL", "somedefaultvalue");
        $port = env("WHATSAPP_PORT", "somedefaultvalue");
        $apikey = env("WHATSAPP_APIKEY", "somedefaultvalue");
        try {
            $client = new Client();
            $headers = [
                'apikey' => $apikey
            ];
            $request = new Request('GET', $hostname . ':' . $port . '/instance/connect/' . $name, $headers);
            $res = $client->sendAsync($request)->wait();
            $res = json_decode($res->getBody(), true);
        } catch (ClientException $ex) {
            return true;
        }catch( ConnectException $ex){
            return false;
        }
        return false;
    }


    public static function sender($name, $client_phone, $message, $image = null)
    {


        $hostname = env("WHATSAPP_URL", "somedefaultvalue");
        $port = env("WHATSAPP_PORT", "somedefaultvalue");
        $apikey = env("WHATSAPP_APIKEY", "somedefaultvalue");
        try {
            $client = new Client();
            $headers = [
                'Content-Type' => 'application/json',
                'apikey' => $apikey
            ];
            $body = array(
                "number" => $client_phone,
                "options" => array(
                    "externalAttributes" => "<any> - optional",
                    "delay" => 1200,
                    "presence" => "composing"
                ),
                "textMessage" => array(
                    "text" => $message,
                )
            );
            $body = json_encode($body);
            $request = new Request('POST', $hostname . ':' . $port . '/message/sendText/' . $name, $headers, $body);
            $res = $client->sendAsync($request)->wait();
            return true;
        } catch (ClientException $ex) {
            return false;
        }
    }


    /**
     * Send bulk messages
     * NOT TESTED YET
     */
    public static function senderBulk($name, $data)
    {
        foreach ($data as $item) {
            self::sender($name, $item['phone'], $item['message']);
        }
    }

    public static function hasInstance($name)
    {

        $hostname = env("WHATSAPP_URL", "somedefaultvalue");
        $port = env("WHATSAPP_PORT", "somedefaultvalue");
        $apikey = env("WHATSAPP_APIKEY", "somedefaultvalue");
        try {
            $client = new Client();
            $headers = [
                'apikey' => $apikey
            ];
            $body = json_encode(array(
                "instanceName" => $name,
                "description" => "Instance: " . $name . " V1"
            ));

            $request = new Request('POST', $hostname . ':' . $port . '/instance/create', $headers, $body);
            $res = $client->sendAsync($request)->wait();
            $res = json_decode($res->getBody(), true);
        } catch (ClientException $ex) {
            return true;
        }catch( ConnectException $ex){
            return false;
        }
        return false;
    }
    public static function qr($name)
    {
        self::hasInstance($name);


        $hostname = env("WHATSAPP_URL", "somedefaultvalue");
        $port = env("WHATSAPP_PORT", "somedefaultvalue");
        $apikey = env("WHATSAPP_APIKEY", "somedefaultvalue");

        try{
        $client = new Client();
        $headers = [
            'apikey' => $apikey
        ];
        $request = new Request('GET', $hostname . ':' . $port . '/instance/connect/' . $name, $headers);
        $res = $client->sendAsync($request)->wait();
        $res = json_decode($res->getBody(), true);

        return $res;
    }catch( ConnectException $ex){
        return false;
    }

    }
}
