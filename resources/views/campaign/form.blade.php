@php
    $isEdit = !empty($campaign->id);
    $route = $isEdit 
        ? route('campaigns.update', $campaign->id) 
        : route('campaigns.store');
@endphp

<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-4">
        <form method="POST" action="{{ $route }}" role="form">
            @csrf
            @if($isEdit)
                @method('PATCH')
            @endif

            <div class="row">
                <div class="col-md-8">
                    <div class="mb-4">
                        {{ Form::label('name', 'Nome Estratégico da Campanha', ['class' => 'form-label fw-bold']) }}
                        <div class="input-group">
                            <span class="input-group-text bg-light"><i class="bi bi-tag-fill text-primary"></i></span>
                            {{ Form::text('name', $campaign->name, [
                                'class' => 'form-control form-control-lg' . ($errors->has('name') ? ' is-invalid' : ''), 
                                'placeholder' => 'Ex: Lançamento Março 2026 - Minerador A', 
                                'required'
                            ]) }}
                            {!! $errors->first('name', '<div class="invalid-feedback">:message</div>') !!}
                        </div>
                        <small class="text-muted">Use nomes claros para identificar o lote de contatos e a oferta.</small>
                    </div>
                </div>

                <div class="col-md-4 d-flex align-items-center justify-content-center">
                    <div class="text-center p-3 border rounded-3 bg-light w-100">
                        <i class="bi bi-info-circle text-primary fs-3"></i>
                        <p class="small mb-0 mt-2">As campanhas agrupam suas mensagens e listas de contatos para processamento paralelo.</p>
                    </div>
                </div>
            </div>

            <hr class="my-4">

            <div class="d-flex justify-content-end gap-2">
                <a href="{{ route('campaigns.index') }}" class="btn btn-light border px-4">Cancelar</a>
                <button type="submit" class="btn btn-primary btn-lg px-5 shadow-sm">
                    <i class="bi bi-check-lg"></i> {{ $isEdit ? 'Atualizar Campanha' : 'Criar Campanha' }}
                </button>
            </div>
        </form>
    </div>
</div>