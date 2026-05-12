<h2>Hello, {{ $instance->user->name }}</h2>
<p>Your instance <strong>{{ $instance->name }}</strong> ({{ $instance->instance_name }}) has been disconnected.</p>
<p>To keep sending messages, you need to scan the QR code again to reconnect.</p>
<br>
<a href="{{ route('instances.show', $instance->id) }}" 
   style="background: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">
   Reconnect now
</a>