<h2>Olá, {{ $instance->user->name }}</h2>
<p>A sua instância <strong>{{ $instance->name }}</strong> ({{ $instance->instance_name }}) foi desconectada.</p>
<p>Para continuar enviando mensagens, você precisa reconectar o QR Code.</p>
<br>
<a href="{{ route('instances.show', $instance->id) }}" 
   style="background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">
   Reconectar Agora
</a>