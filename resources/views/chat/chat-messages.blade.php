<div class="d-flex flex-column p-3" style="background-color: #e5ddd5; min-height: 500px;">
    @foreach($messages as $record)
        @php
            $isMe = $record['key']['fromMe'] ?? false;
            $content = $record['message']['conversation'] ?? '';
            $timestamp = $record['messageTimestamp'] ?? null;
            $pushName = $record['pushName'] ?? 'Desconhecido';
        @endphp

        <div class="d-flex mb-2 {{ $isMe ? 'justify-content-end' : 'justify-content-start' }}">
            <div class="p-2 shadow-sm {{ $isMe ? 'bg-success-subtle' : 'bg-white' }}" 
                 style="max-width: 70%; border-radius: 10px; position: relative; border-{{ $isMe ? 'tr' : 'tl' }}-radius: 0;">
                
                {{-- Nome do Remetente (Apenas se não for eu) --}}
                @if(!$isMe)
                    <div class="fw-bold small mb-1" style="color: #35a5d4;">
                        {{ $pushName }}
                    </div>
                @endif

                {{-- Conteúdo da Mensagem --}}
                <div class="text-dark mb-1" style="font-size: 0.95rem; line-height: 1.4;">
                    {{ $content }}
                </div>

                {{-- Metadados: Hora e Origem --}}
                <div class="d-flex align-items-center justify-content-end gap-1" style="font-size: 0.7rem; color: #727272;">
                    <span>{{ $timestamp ? date('H:i', $timestamp) : '' }}</span>
                    
                    @if($isMe)
                        <i class="bi bi-check2-all text-info"></i> {{-- Requer Bootstrap Icons --}}
                    @endif
                    
                    @if(isset($record['source']))
                        <span class="opacity-50">
                            @if($record['source'] === 'ios') <i class="bi bi-apple"></i> 
                            @else <i class="bi bi-android2"></i> @endif
                        </span>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
</div>