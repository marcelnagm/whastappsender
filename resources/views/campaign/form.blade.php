<div class="box box-info padding-1">
    <div class="box-body">        
        <div class="form-group">
            {{ Form::label('name') }}
            {{ Form::text('name', $campaign->name, ['class' => 'form-control' . ($errors->has('name') ? ' is-invalid' : ''), 'placeholder' => 'Name', 'required']) }}
            {!! $errors->first('name', '<div class="invalid-feedback">:message</div>') !!}
        </div>
    </div>
    <div class="box-footer mt20">
        <button type="submit" class="btn btn-primary">Submit</button>
    </div>
</div>