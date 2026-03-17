@extends('layouts.app-master')

@section('content')
@php
// --- LÓGICA DE CÁLCULO ANALÍTICO (Não altera o Controller) ---
$statusInterno = $statsStatus->toArray();
$processados = $statusInterno['processado'] ?? 0;
$erros = $statusInterno['erro'] ?? 0;
$pendentes = $statusInterno['pendente'] ?? 0;

$totalGeral = $processados + $erros + $pendentes;

// Função para calcular porcentagem com segurança
$calcPercent = function($parcial, $total) {
return $total > 0 ? round(($parcial / $total) * 100, 1) : 0;
};

$pctSucesso = $calcPercent($processados, $totalGeral);
$pctErro = $calcPercent($erros, $totalGeral);
$pctFila = $calcPercent($pendentes, $totalGeral);

// Estatísticas Evolution
$totalEvolution = array_sum($statsEvolution->toArray());
@endphp

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0 text-gray-800 fw-bold">
            <i class="bi bi-cpu text-primary me-2"></i>Logs de Execução
        </h1>
        <div class="text-end">
            <span class="badge bg-secondary">ID Ref: {{ $id }}</span>
            <div class="x-small text-muted mt-1 fw-bold text-uppercase">Total na Base: {{ $totalGeral }}</div>
        </div>
    </div>

    <div class="row mb-4 g-3">
        <div class="col-md-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <label class="text-muted small fw-bold text-uppercase mb-3 d-block">Status Interno (Sistema)</label>
                    <div class="row text-center mb-3">
                        <div class="col border-end">
                            <div class="h4 fw-bold mb-0 text-success">{{ $processados }}</div>
                            <div class="x-small text-muted text-uppercase">Sucesso</div>
                            <div class="fw-bold text-success small">{{ $pctSucesso }}%</div>
                        </div>
                        <div class="col border-end">
                            <div class="h4 fw-bold mb-0 text-danger">{{ $erros }}</div>
                            <div class="x-small text-muted text-uppercase">Falhas</div>
                            <div class="fw-bold text-danger small">{{ $pctErro }}%</div>
                        </div>
                        <div class="col text-warning">
                            <div class="h4 fw-bold mb-0 {{ $pendentes > 0 ? 'text-warning' : 'text-muted' }}">
                                {{ $pendentes }}
                            </div>
                            <div class="x-small text-muted text-uppercase">Fila</div>
                            <div class="fw-bold text-warning small">{{ $pctFila }}%</div>
                        </div>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div class="progress-bar bg-success" role="progressbar" style="width: {{ $pctSucesso }}%"></div>
                        <div class="progress-bar bg-danger" role="progressbar" style="width: {{ $pctErro }}%"></div>
                        <div class="progress-bar bg-warning" role="progressbar" style="width: {{ $pctFila }}%"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <label class="text-muted small fw-bold text-uppercase mb-3 d-block">Retorno Evolution API (Eficiência de Entrega)</label>
                    <div class="d-flex gap-2 overflow-auto pb-2">
                        @forelse($statsEvolution as $name => $total)
                        <div class="bg-light p-2 rounded border-start border-3 border-info min-w-120px text-center">
                            <div class="fw-bold h5 mb-0">{{ $total }}</div>
                            <div class="x-small text-muted text-uppercase text-truncate" title="{{ $name }}">{{ $name }}</div>
                            <div class="fw-bold text-info x-small">{{ $calcPercent($total, $totalEvolution) }}%</div>
                        </div>
                        @empty
                        <div class="text-muted small py-2 italic">Aguardando retornos da API...</div>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="x-small fw-bold text-muted">Item ID</label>
                    <input type="text" name="campaign_item_id" class="form-control form-control-sm" value="{{ request('campaign_item_id') }}" placeholder="Filtrar por Item">
                </div>
                <div class="col-md-2">
                    <label class="x-small fw-bold text-muted">Contato/Tel</label>
                    <input type="text" name="contact" class="form-control form-control-sm" value="{{ request('contact') }}" placeholder="Busca no payload">
                </div>
                <div class="col-md-2">
                    <label class="x-small fw-bold text-muted">Status Interno</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <option value="processado" {{ request('status') == 'processado' ? 'selected' : '' }}>Processado</option>
                        <option value="erro" {{ request('status') == 'erro' ? 'selected' : '' }}>Erro</option>
                        <option value="pendente" {{ request('status') == 'pendente' ? 'selected' : '' }}>Pendente</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="x-small fw-bold text-muted">Status Evolution</label>
                    <select name="evolution_status" class="form-select form-select-sm">
                        <option value="">Todos os Retornos</option>
                        @foreach($statsEvolution as $name => $total)
                        <option value="{{ $name }}" {{ request('evolution_status') == $name ? 'selected' : '' }}>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <div class="d-flex gap-1">
                        <button type="submit" class="btn btn-primary btn-sm w-100">Aplicar Filtros</button>
                        <a href="{{ route('whatsapp-jobs.index', $id) }}" class="btn btn-outline-secondary btn-sm w-100">Limpar</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light small text-uppercase font-monospace">
                    <tr>
                        <th class="ps-4">ID / Item</th>
                        <th>Status</th>
                        <th>Evolution</th>
                        <th>Dados</th>
                        <th class="text-danger">Mensagem de Erro</th>
                        <th class="text-center">Debug</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($jobs as $job)
                    <tr>
                        <td class="ps-4">
                            <div class="fw-bold">#{{ $job->id }}</div>
                            <span class="badge bg-light text-dark border x-small">Item: {{ $job->campaign_item_id }}</span>
                        </td>
                        <td>
                            <span class="badge {{ $job->status == 'processado' ? 'bg-success' : ($job->status == 'erro' ? 'bg-danger' : 'bg-warning text-dark') }}">
                                {{ $job->status }}
                            </span>
                        </td>
                        <td class="small fw-bold text-primary">{{ $job->evolution_status ?? '---' }}</td>
                        <td>
                            <div class="btn-group">
                                <button type="button" title="Payload" class="btn btn-xs btn-outline-secondary js-copy" data-value="{{ json_encode($job->payload) }}">
                                    <i class="bi bi-file-earmark-code"></i>
                                </button>
                                @if($job->resposta)
                                <button type="button" title="Resposta" class="btn btn-xs btn-outline-primary js-copy" data-value="{{ json_encode($job->resposta) }}">
                                    <i class="bi bi-chat-left-dots"></i>
                                </button>
                                @endif
                            </div>
                        </td>
                        <td class="small text-danger">
                            <span title="{{ $job->erro_mensagem }}">
                                {{ $job->erro_mensagem ? \Illuminate\Support\Str::limit($job->erro_mensagem, 35) : '---' }}
                            </span>
                        </td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-dark js-curl"
                                data-endpoint="{{ $job->endpoint }}"
                                data-payload="{{ json_encode($job->payload) }}">
                                <i class="bi bi-terminal"></i>
                            </button>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="text-center py-5 text-muted">
                            <i class="bi bi-search h1 d-block mb-3"></i>
                            Nenhum registro encontrado.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="py-4 d-flex justify-content-center">
    {{ $jobs->appends(request()->query())->links('pagination::bootstrap-4') }}
</div>
</div>
@endsection

@section('js')
<script>
    (function() {
        "use strict";

        function fallbackCopy(text, btn) {
            const area = document.createElement('textarea');
            area.value = text;
            area.style.position = 'fixed';
            area.style.left = '-9999px';
            document.body.appendChild(area);
            area.focus();
            area.select();

            try {
                if (document.execCommand('copy')) {
                    const originalHtml = btn.innerHTML;
                    const originalClass = btn.className;
                    btn.innerHTML = '<i class="bi bi-check-lg"></i>';
                    btn.className = originalClass.replace(/btn-outline-\w+|btn-dark/, 'btn-success');
                    setTimeout(() => {
                        btn.innerHTML = originalHtml;
                        btn.className = originalClass;
                    }, 1200);
                }
            } catch (err) {
                console.error('Falha ao copiar.');
            }
            document.body.removeChild(area);
        }

        document.addEventListener('click', function(e) {
            const btnCopy = e.target.closest('.js-copy');
            if (btnCopy) {
                const val = btnCopy.getAttribute('data-value');
                try {
                    const json = JSON.parse(val);
                    fallbackCopy(JSON.stringify(json, null, 2), btnCopy);
                } catch {
                    fallbackCopy(val, btnCopy);
                }
            }

            const btnCurl = e.target.closest('.js-curl');
            if (btnCurl) {
                const endpoint = btnCurl.getAttribute('data-endpoint');
                const payload = btnCurl.getAttribute('data-payload');
                const protocol = "{{ config('services.whatsapp.protocol', 'http') }}";
                const host = "{{ config('services.whatsapp.url', 'localhost') }}";
                const port = "{{ config('services.whatsapp.port', '8080') }}";
                const apikey = "{{ config('services.whatsapp.apikey') }}";

                const curl = `curl --location '${protocol}://${host}:${port}${endpoint}' \\
--header 'Content-Type: application/json' \\
--header 'apikey: ${apikey}' \\
--data-raw '${payload}'`;
                fallbackCopy(curl, btnCurl);
            }
        });
    })();
</script>
@endsection

<style>
    .x-small {
        font-size: 0.7rem;
    }

    .btn-xs {
        padding: 0.2rem 0.4rem;
        font-size: 0.72rem;
    }

    .min-w-120px {
        min-width: 120px;
        flex-shrink: 0;
    }

    .overflow-auto::-webkit-scrollbar {
        height: 4px;
    }

    .overflow-auto::-webkit-scrollbar-thumb {
        background: #ced4da;
        border-radius: 10px;
    }

    .progress {
        background-color: #f0f0f0;
        border-radius: 10px;
    }

    <style>
    /* ... seus estilos anteriores ... */

    /* CORREÇÃO DO PAGINATE GIGANTE */
    nav[role="navigation"] svg {
        width: 20px;
        height: 20px;
    }

    .pagination {
        margin-bottom: 0;
        display: flex;
        gap: 2px;
    }

    .page-item.active .page-link {
        background-color: #0d6efd;
        border-color: #0d6efd;
    }

    .x-small {
        font-size: 0.7rem;
    }

    .btn-xs {
        padding: 0.2rem 0.4rem;
        font-size: 0.72rem;
    }

</style>