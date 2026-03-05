<div class="card border-0 shadow-sm rounded-4 overflow-hidden">
    <div class="card-header bg-white border-0 py-3">
        <h5 class="mb-0 fw-bold text-dark">
            <i class="bi bi-person-plus me-2 text-primary"></i>Informações do Contato
        </h5>
    </div>
    
    <div class="card-body bg-light-subtle p-4">
        <div class="row">
            <div class="col-md-6">
                <div class="form-floating mb-3">
                    {{ Form::text('name', $contact->name, [
                        'class' => 'form-control border-0 shadow-sm' . ($errors->has('name') ? ' is-invalid' : ''), 
                        'placeholder' => 'Nome completo',
                        'id' => 'name'
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
                        'id' => 'contact'
                    ]) }}
                    {{ Form::label('contact', 'Número de Telefone / WhatsApp') }}
                    {!! $errors->first('contact', '<div class="invalid-feedback">:message</div>') !!}
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
    .form-floating > .form-control:focus {
        border-color: #0d6efd;
        box-shadow: 0 4px 10px rgba(13, 110, 253, 0.1) !important;
    }
    .form-floating > label {
        padding-left: 1rem;
        color: #6c757d;
    }
</style>