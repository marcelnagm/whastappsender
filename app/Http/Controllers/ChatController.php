<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Message; // Supondo que você tenha essa model
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class ChatController extends Controller
{
    /**
     * Lista inicial de conversas.
     * Priorizamos contatos com mensagens recentes.
     */
    public function index(Request $request)
    {
        // Configurações da Instância (Pode ser movido para o Model ou .env futuramente)
        $instanceName = '5595981110695';
        $apiKey = 'BQYHJGJHJ';
        $redisKey = "chats:{$instanceName}";

        $chats = [];

        try {
            // Lógica: Se pedir refresh OU o cache não existir no Redis
            if ($request->has('refresh') || !Redis::exists($redisKey)) {

                $response = Http::withHeaders([
                    'apikey' => $apiKey
                ])->post("http://localhost:8080/chat/findChats/{$instanceName}");

                if ($response->successful()) {
                    $chats = $response->json();

                    // Salva no Redis como String JSON por 30 minutos (1800 segundos)
                    Redis::setex($redisKey, 1800, json_encode($chats));
                } else {
                    // Se a API falhar mas houver algo no cache, usa o cache como fallback
                    $cachedData = Redis::get($redisKey);
                    $chats = $cachedData ? json_decode($cachedData, true) : [];
                }
            } else {
                // Busca direta do Redis (O 'true' converte stdClass em Array)
                $cachedData = Redis::get($redisKey);
                $chats = json_decode($cachedData, true) ?? [];
            }
        } catch (\Exception $e) {
            // Log do erro se necessário: \Log::error($e->getMessage());
            // Se tudo falhar (Redis fora ou API fora), garante que $chats seja um array vazio
            $chats = [];
        }

        // Ordenação Analítica: Colocar os chats mais recentes (updatedAt) no topo
        if (!empty($chats)) {
            usort($chats, function ($a, $b) {
                return strtotime($b['updatedAt'] ?? 0) <=> strtotime($a['updatedAt'] ?? 0);
            });
        }

        return view('chat.index', compact('chats'));
    }

    /**
     * Carrega as mensagens de um contato específico via AJAX.
     */
    public function show(Request $request)
    {
        // O JID vem do corpo do POST disparado pelo seu JavaScript
        $jid = $request->input('jid');

        if (!$jid) {
            return response()->json(['error' => 'JID não identificado'], 400);
        }

        $instanceName = '5595981110695';
        $apiKey = 'BQYHJGJHJ';
        $redisKey = "messages:{$instanceName}:" . md5($jid);

        try {
            // Lógica de Cache: Se não estiver no Redis, consulta a API
            if (!Redis::exists($redisKey)) {

                $response = Http::withHeaders([
                    'Content-Type' => 'application/json',
                    'apikey' => $apiKey
                ])->post("http://localhost:8080/chat/findMessages/{$instanceName}", [
                    'where' => [
                        'key' => [
                            'remoteJid' => $jid // Dinâmico com base no clique
                        ]
                    ],
                    'page' => 1,
                    'offset' => 10 // Conforme seu CURL original
                ]);

                if ($response->successful()) {
                    $data = $response->json();
                    // dd($data);
                    // A Evolution API costuma retornar as mensagens dentro da chave 'records' ou direto no array
                    $messages = $data['records'] ?? $data;

                    // Cache de 5 minutos (300 segundos) para manter o espelho leve
                    Redis::setex($redisKey, 300, json_encode($messages));
                } else {
                    return response()->json(['error' => 'Falha na comunicação com Evolution API'], 500);
                }
            } else {
                // Decodifica do Redis garantindo que seja um Array Associativo (true)
                $messages = json_decode(Redis::get($redisKey), true) ?? [];
            }
            $messages = $messages['messages']['records'];
            // Renderiza apenas o fragmento HTML das bolhas de conversa
            return view('chat.chat-messages', compact('messages'))->render();
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function sendMessage(Request $request)
    {
        $instanceName = '5595981110695';
        
        $request->validate([
            'jid' => 'required|string',
            'message' => 'required|string'
        ]);

        $rawJid = $request->input('jid');
        $messageText = $request->input('message');

        // Limpeza do JID: remove sufixos para enviar apenas o número conforme o cURL
        $number =  $rawJid;

        try {
            // 1. DISPARO PARA EVOLUTION API
            $response = Http::withHeaders([
                'apikey' => 'BQYHJGJHJ',
                'Content-Type' => 'application/json',
            ])->post("http://localhost:8080/message/sendText/{$instanceName}", [
                'number' => $number,
                'text' => $messageText,
                "linkPreview" => false
            ]);

            if ($response->failed()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Falha na API externa: ' . $response->body()
                ], 502);
            }

            // 2. ESTRUTURA DO MOCK PARA O REDIS (Mantendo compatibilidade com seu JSON)
            $newMessage = [
                'id' => (string) Str::uuid(),
                'key' => [
                    'id' => strtoupper(Str::random(20)),
                    'fromMe' => true,
                    'remoteJid' => $rawJid
                ],
                'pushName' => auth()->user()->name ?? 'Marcel Nagm',
                'messageType' => 'conversation',
                'message' => [
                    'conversation' => $messageText
                ],
                'messageTimestamp' => time(),
                'source' => 'android', // Simulando origem
                'MessageUpdate' => []
            ];

            // 3. ATUALIZAÇÃO DO CACHE NO REDIS
            $this->updateRedisChatHistory($rawJid, $newMessage);

            return response()->json([
                'success' => true,
                'data' => $newMessage
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Erro interno: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Atualiza o histórico no Redis para refletir a nova mensagem imediatamente
     */
    private function updateRedisChatHistory($jid, $newMessage)
    {
        $redisKey = "chat:messages:{$jid}";

        // Tenta recuperar o histórico atual ou cria a estrutura base
        $cachedData = json_decode(Redis::get($redisKey), true) ?: [
            'messages' => [
                'total' => 0,
                'pages' => 1,
                'currentPage' => 1,
                'records' => []
            ]
        ];

        // Adiciona a nova mensagem no início (LIFO) para o seu partial renderizar corretamente
        array_unshift($cachedData['messages']['records'], $newMessage);
        $cachedData['messages']['total']++;

        // Salva com expiração (ex: 24 horas) para evitar vazamento de memória no Redis
        Redis::setex($redisKey, 86400, json_encode($cachedData));
    }
}
