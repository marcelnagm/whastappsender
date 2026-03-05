
@extends('layouts.app-master')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0 text-gray-800 fw-bold"><i class="bi bi-bug text-danger me-2"></i>Logs de Execução (Jobs)</h1>
    </div>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light small text-uppercase">
                    <tr>
                        <th class="ps-4">ID / Destino</th>
                        <th>Status / Evolution</th>
                        <th>Payload (Envio)</th>
                        <th>Resposta (API)</th>
                        <th class="text-center">Debug</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($jobs as $job)
                    <tr>
                        <td class="ps-4">
                            <div class="fw-bold">#{{ $job->id }}</div>
                            <small class="text-muted">{{ $job->endpoint }}</small>
                        </td>
                        <td>
                            <span class="badge {{ $job->status == 'processado' ? 'bg-success' : 'bg-danger' }}">{{ $job->status }}</span>
                            <div class="x-small text-muted">{{ $job->evolution_status ?? 'N/A' }}</div>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-outline-secondary" onclick="copyToClipboard('{{ json_encode($job->payload) }}')">
                                <i class="bi bi-clipboard"></i> Payload
                            </button>
                        </td>
                        <td>
                            @if($job->resposta)
                                <button class="btn btn-sm btn-outline-primary" onclick="copyToClipboard('{{ json_encode($job->resposta) }}')">
                                    <i class="bi bi-code-slash"></i> Copiar Resposta
                                </button>
                            @else
                                <span class="text-muted small">Sem resposta</span>
                            @endif
                        </td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-dark" onclick="generateCurl('{{ $job->endpoint }}', '{{ json_encode($job->payload) }}')">
                                <i class="bi bi-terminal"></i> cURL
                            </button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <div class="py-3">{!! $jobs->links() !!}</div>
</div>

<div class="modal fade" id="curlModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title">Comando cURL para Debug</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body bg-light">
                <pre id="curlContent" class="p-3 bg-white border rounded shadow-sm text-dark" style="white-space: pre-wrap; word-break: break-all;"></pre>
                <button class="btn btn-primary w-100 mt-3" onclick="copyCurl()">Copiar Comando</button>
            </div>
        </div>
    </div>
</div>
@endsection

@section('js')
<script>
function copyToClipboard(text) {
    try {
        const obj = JSON.parse(text);
        const formatted = JSON.stringify(obj, null, 2);
        navigator.clipboard.writeText(formatted);
        alert('JSON formatado copiado para a área de transferência!');
    } catch (e) {
        navigator.clipboard.writeText(text);
        alert('Texto copiado!');
    }
}

function generateCurl(url, payload) {
    const data = JSON.parse(payload);
    // Substitua 'SUA_API_KEY' pela variável real se necessário
    const curl = `curl --location '${url}' \\
--header 'Content-Type: application/json' \\
--header 'apikey: SEU_TOKEN_AQUI' \\
--data '${JSON.stringify(data)}'`;

    document.getElementById('curlContent').innerText = curl;
    new bootstrap.Modal(document.getElementById('curlModal')).show();
}

function copyCurl() {
    const text = document.getElementById('curlContent').innerText;
    navigator.clipboard.writeText(text);
    alert('cURL copiado!');
}
</script>
@endsection

<style>
    .x-small { font-size: 0.7rem; }
</style>