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
            @if(!URL::isValidUrl( $campaignItem->image))
            {{ Form::file('image', $campaignItem->image, ['class' => 'form-control' . ($errors->has('text') ? ' is-invalid' : ''),'id' => 'message', 'placeholder' => 'image']) }}
            @else
            {{ Form::text('url', $campaignItem->image, ['class' => 'form-control' . ($errors->has('url') ? ' is-invalid' : ''), 'placeholder' => 'Url da imagem']) }}          
            @endif
            {!! $errors->first('text', '<div class="invalid-feedback">:message</div>') !!}
        </div>
        <div class="form-group">
            {{ Form::label('user_id') }}
            {{ Form::text('user_id', $campaignItem->user_id, ['class' => 'form-control' . ($errors->has('user_id') ? ' is-invalid' : ''), 'placeholder' => 'User Id']) }}
            {!! $errors->first('user_id', '<div class="invalid-feedback">:message</div>') !!}
        </div>
        <div class="form-group">
            {{ Form::label('campaign_id') }}
            {{ Form::text('campaign_id', $campaignItem->campaign_id, ['class' => 'form-control' . ($errors->has('campaign_id') ? ' is-invalid' : ''), 'placeholder' => 'Campaign Id']) }}
            {!! $errors->first('campaign_id', '<div class="invalid-feedback">:message</div>') !!}
        </div>

    </div>
    <div class="box-footer mt20">
        <button onclick="sendForm()" type="submit" class="btn btn-primary">Submit</button>
    </div>
</div>


