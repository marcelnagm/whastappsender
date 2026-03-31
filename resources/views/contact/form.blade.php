<div class="card border-0 shadow-sm rounded-4 overflow-hidden">
    <div class="card-header bg-white border-0 py-3 border-bottom">
        <h5 class="mb-0 fw-bold text-dark">
            <i class="bi bi-person-plus me-2 text-primary"></i>Informações do Contato
        </h5>
    </div>
    
    <div class="card-body bg-light-subtle p-4">
        <div class="row g-3">
            {{-- Linha 1: Nome e WhatsApp --}}
            <div class="col-md-6">
                <div class="form-floating mb-3">
                    {{ Form::text('name', $contact->name, [
                        'class' => 'form-control border-0 shadow-sm' . ($errors->has('name') ? ' is-invalid' : ''), 
                        'placeholder' => 'Nome completo',
                        'id' => 'name',
                        'required' => 'required'
                    ]) }}
                    {{ Form::label('name', 'Nome do Contato') }}
                    {!! $errors->first('name', '<div class="invalid-feedback">:message</div>') !!}
                </div>
            </div>

            <div class="col-md-6">
                <div class="form-floating mb-3">
                    {{ Form::text('contact', $contact->contactFormat(), [
                        'class' => 'form-control border-0 shadow-sm' . ($errors->has('contact') ? ' is-invalid' : ''), 
                        'placeholder' => 'Contato',
                        'id' => 'contact',
                        'required' => 'required'
                    ]) }}
                    {{ Form::label('contact', 'Número de Telefone / WhatsApp') }}
                    {!! $errors->first('contact', '<div class="invalid-feedback">:message</div>') !!}
                </div>
            </div>

            {{-- Linha 2: E-mail e LID --}}
            <div class="col-md-8">
                <div class="form-floating mb-3">
                    {{ Form::email('email', $contact->email, [
                        'class' => 'form-control border-0 shadow-sm' . ($errors->has('email') ? ' is-invalid' : ''), 
                        'placeholder' => 'E-mail',
                        'id' => 'email'
                    ]) }}
                    {{ Form::label('email', 'Endereço de E-mail') }}
                    {!! $errors->first('email', '<div class="invalid-feedback">:message</div>') !!}
                </div>
            </div>

            <div class="col-md-4">
                <div class="form-floating mb-3">
                    {{ Form::text('lid', $contact->lid, [
                        'class' => 'form-control border-0 shadow-sm' . ($errors->has('lid') ? ' is-invalid' : ''), 
                        'placeholder' => 'LID / Origem',
                        'id' => 'lid',
                        'readonly' => 'readonly'
                    ]) }}
                    {{ Form::label('lid', 'LID (Origem do Lead)') }}
                    {!! $errors->first('lid', '<div class="invalid-feedback">:message</div>') !!}
                </div>
            </div>

            {{-- Linha 3: Status e Score --}}
            <div class="col-md-6">
                <div class="form-floating mb-3">
                    {{ Form::select('status', [
                        'ativo' => 'Ativo',
                        'inativo' => 'Inativo',
                        'no-whatsapp' => 'Não possui WhatsApp'
                    ], $contact->status, [
                        'class' => 'form-select border-0 shadow-sm' . ($errors->has('status') ? ' is-invalid' : ''),
                        'id' => 'status'
                    ]) }}
                    {{ Form::label('status', 'Status do Cadastro') }}
                    {!! $errors->first('status', '<div class="invalid-feedback">:message</div>') !!}
                </div>
            </div>

            <div class="col-md-6">
                <div class="form-floating mb-3">
                    {{ Form::number('score', $contact->score, [
                        'class' => 'form-control border-0 shadow-sm' . ($errors->has('score') ? ' is-invalid' : ''), 
                        'placeholder' => 'Score',
                        'id' => 'score',
                        'min' => 0,
                        'max' => 100
                    ]) }}
                    {{ Form::label('score', 'Score / Temperatura (0-100)') }}
                    {!! $errors->first('score', '<div class="invalid-feedback">:message</div>') !!}
                </div>
            </div>
        </div>
    </div>

    <div class="card-footer bg-white border-0 p-4 pt-0 d-flex justify-content-end gap-2">
        <button type="submit" class="btn btn-primary px-5 py-2 fw-bold shadow-sm rounded-3 text-uppercase" style="letter-spacing: 0.5px;">
            <i class="bi bi-check2-circle me-1"></i> Salvar Contato
        </button>
    </div>
</div>

<style>
    .form-floating > .form-control:focus, 
    .form-floating > .form-select:focus {
        border-color: #0d6efd;
        box-shadow: 0 4px 12px rgba(13, 110, 253, 0.12) !important;
        background-color: #fff;
    }
    .form-floating > label {
        padding-left: 1rem;
        color: #6c757d;
        font-weight: 600;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    .form-control, .form-select {
        border-radius: 10px;
    }
</style>