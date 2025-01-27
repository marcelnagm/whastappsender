<div class="box box-info padding-1">
    <div class="box-body">

        <div class="form-group">
            {{ Form::label('name') }}
            {{ Form::text('name', $campaignItem->name, ['class' => 'form-control' . ($errors->has('name') ? ' is-invalid' : ''), 'placeholder' => 'Name']) }}
            {!! $errors->first('name', '<div class="invalid-feedback">:message</div>') !!}
        </div>
        <div class="form-group">
            {{ Form::label('text') }}
            {{ Form::textarea('text', $campaignItem->text, ['class' => 'form-control' . ($errors->has('text') ? ' is-invalid' : ''),'id' => 'message', 'placeholder' => 'Text']) }}

            {!! $errors->first('text', '<div class="invalid-feedback">:message</div>') !!}
        </div>
        <div class="form-group">
            {{ Form::label('image') }}
            {{ Form::text('image', $campaignItem->image, ['class' => 'form-control' . ($errors->has('text') ? ' is-invalid' : ''),'id' => 'message', 'placeholder' => 'image']) }}
            {!! $errors->first('text', '<div class="invalid-feedback">:message</div>') !!}
        </div>
        <div class="form-group">
            {{ Form::label('campaign_id') }}
            {{ Form::select('campaign_id', $campaigns, $campaignItem->campaign_id, ['class' => 'form-control' . ($errors->has('campaign_id') ? ' is-invalid' : ''), 'placeholder' => 'Campaign Id']) }}
            {!! $errors->first('campaign_id', '<div class="invalid-feedback">:message</div>') !!}
        </div>

    </div>
    <div class="box-footer mt20">
        <button onclick="sendForm()" type="submit" class="btn btn-primary">Submit</button>
    </div>
</div>